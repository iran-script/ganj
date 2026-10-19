import 'dart:async';
import 'dart:convert';
import 'dart:io';
import 'dart:typed_data';

import 'package:flutter/foundation.dart';
import 'package:flutter/services.dart';
import 'package:http/http.dart' as http;
import 'package:uuid/uuid.dart';

import '../config/app_config.dart';
import '../errors/api_error.dart';
import '../device/device_identity.dart';
import '../storage/secure_store.dart';
import '../offline/offline_identity.dart';
import '../auth/session_expiry.dart';

class ApiClient {
  ApiClient({SecureStore? store, http.Client? client})
      : _client = client,
        _store = store ?? SecureStore(),
        _device = DeviceIdentity(store ?? SecureStore());

  static const _tokenKey = 'nexo.access_token';
  final http.Client? _client;
  final SecureStore _store;
  final DeviceIdentity _device;

  Future<Map<String, dynamic>> get(String path, {bool device = false}) async {
    return _send('GET', path, device: device);
  }

  Future<Map<String, dynamic>> post(
    String path, {
    Map<String, dynamic> body = const {},
    bool device = false,
    bool idempotent = false,
    String? idempotencyKey,
    OfflineOwner? expectedOwner,
  }) async {
    return _send('POST', path,
        body: body,
        device: device,
        idempotent: idempotent,
        idempotencyKey: idempotencyKey,
        expectedOwner: expectedOwner);
  }

  Future<Map<String, dynamic>> patch(
    String path, {
    required Map<String, dynamic> body,
    bool device = false,
  }) async {
    return _send('PATCH', path, body: body, device: device);
  }

  Future<Map<String, dynamic>> delete(String path) async {
    return _send('DELETE', path);
  }

  Future<Map<String, dynamic>> _send(
    String method,
    String path, {
    Map<String, dynamic> body = const {},
    bool device = false,
    bool idempotent = false,
    String? idempotencyKey,
    OfflineOwner? expectedOwner,
  }) async {
    final diagnosticRequest = _diagnosticRequest(path);
    final diagnosticEndpoint = _safeDiagnosticEndpoint(path);
    final authDiagnostic =
        path == '/auth/login' || path == '/auth/register' || path == '/me';
    final baseUri = _validatedBaseUri();
    if (baseUri == null) {
      if (kDebugMode && diagnosticRequest) {
        debugPrint(
            'NEXO $method request failed kind=insecure_or_invalid_endpoint endpoint=$diagnosticEndpoint');
      }
      throw const ApiError(code: 'INSECURE_ENDPOINT', message: '');
    }
    final headers = <String, String>{
      'Accept': 'application/json',
      'Content-Type': 'application/json'
    };
    String? token;
    String? deviceUuid;
    try {
      token = await _store.read(_tokenKey);
      deviceUuid = device ? await _device.getOrCreate() : null;
    } catch (failure) {
      if (kDebugMode && diagnosticRequest) {
        debugPrint(
            'NEXO $method request failed kind=${diagnosticFailureKind(failure)} endpoint=$diagnosticEndpoint');
      }
      rethrow;
    }
    if (token != null && token.isNotEmpty) {
      headers['Authorization'] = 'Bearer $token';
    }
    if (device) headers['X-Device-Id'] = deviceUuid!;
    if (expectedOwner != null &&
        (!expectedOwner.matches(OfflineIdentity.instance.owner) ||
            OfflineIdentity.instance.authenticationRequired ||
            deviceUuid != expectedOwner.deviceUuid)) {
      throw const ApiError(
          code: 'AUTHENTICATION_REQUIRED', message: 'Replay identity changed.');
    }
    if (idempotent) {
      headers['Idempotency-Key'] = idempotencyKey ?? const Uuid().v4();
    }
    if (kDebugMode && authDiagnostic) {
      debugPrint(
          'NEXO $method ${AppConfig.diagnosticApiBaseUrl}$path authorization=${headers.containsKey('Authorization')} device_header=${headers.containsKey('X-Device-Id')}');
    }
    final request =
        http.Request(method, baseUri.replace(path: '${baseUri.path}$path'))
          ..headers.addAll(headers);
    if (method != 'GET') {
      request.body = jsonEncode(body);
    }
    final client = _client ?? http.Client();
    late http.StreamedResponse response;
    late String text;
    try {
      response =
          await client.send(request).timeout(const Duration(seconds: 20));
      text = await response.stream
          .bytesToString()
          .timeout(const Duration(seconds: 20));
    } catch (failure) {
      if (kDebugMode && diagnosticRequest) {
        debugPrint(
            'NEXO $method request failed kind=${diagnosticFailureKind(failure)} endpoint=$diagnosticEndpoint');
      }
      rethrow;
    } finally {
      if (_client == null) client.close();
    }
    if (kDebugMode && diagnosticRequest) {
      debugPrint(
          'NEXO $method $diagnosticEndpoint HTTP ${response.statusCode}');
    }
    Map<String, dynamic> decoded;
    try {
      final value = text.isEmpty ? <String, dynamic>{} : jsonDecode(text);
      if (value is! Map<String, dynamic>) {
        throw const FormatException('Expected JSON object.');
      }
      decoded = value;
    } on FormatException {
      if (kDebugMode && diagnosticRequest) {
        debugPrint(
            'NEXO $method response failed kind=invalid_json status=${response.statusCode} endpoint=$diagnosticEndpoint');
      }
      throw const ApiError(code: 'INVALID_RESPONSE', message: '');
    }
    final nestedData = decoded['data'] is Map<String, dynamic>
        ? decoded['data'] as Map<String, dynamic>
        : null;
    if (response.statusCode < 200 ||
        response.statusCode >= 300 ||
        decoded['error'] != null ||
        nestedData?['error'] != null) {
      final error = (decoded['error'] ??
          nestedData?['error'] ??
          <String, dynamic>{}) as Map<String, dynamic>;
      if (kDebugMode && diagnosticRequest) {
        final rawCode = error['code'];
        final safeCode =
            rawCode is String && RegExp(r'^[A-Z0-9_]{1,64}$').hasMatch(rawCode)
                ? rawCode
                : 'UNKNOWN_ERROR';
        debugPrint(
            'NEXO $method $diagnosticEndpoint error.status=${response.statusCode} code=$safeCode');
      }
      final code = error['code'] as String? ?? 'UNKNOWN_ERROR';
      if (response.statusCode == 401 &&
          !path.endsWith('/auth/login') &&
          !path.endsWith('/auth/register')) {
        SessionExpiry.instance.expire();
      }
      throw ApiError(
        code: code,
        message: error['message'] as String? ?? '',
        statusCode: response.statusCode,
        details: error['details'] is Map<String, dynamic>
            ? error['details'] as Map<String, dynamic>
            : null,
      );
    }
    if (decoded['data'] is List) {
      return {'items': decoded['data'], 'meta': decoded['meta']};
    }
    return (decoded['data'] ?? <String, dynamic>{}) as Map<String, dynamic>;
  }

  Future<void> saveToken(String token) => _store.write(_tokenKey, token);

  Future<Uint8List> downloadMedia(String path, {String? expectedMime}) async {
    final generation = OfflineIdentity.instance.generation;
    final accessToken = await token();
    final headers = <String, String>{
      'Authorization': 'Bearer ${accessToken ?? ''}',
      'X-Device-Id': await deviceId(),
    };
    if (generation != OfflineIdentity.instance.generation) {
      throw StateError('Identity changed.');
    }
    final client = _client ?? http.Client();
    try {
      final baseUri = _validatedBaseUri();
      if (baseUri == null) {
        throw const ApiError(code: 'INSECURE_ENDPOINT', message: '');
      }
      final request =
          http.Request('GET', baseUri.replace(path: '${baseUri.path}$path'))
            ..headers.addAll(headers);
      final response =
          await client.send(request).timeout(const Duration(seconds: 60));
      if (generation != OfflineIdentity.instance.generation) {
        throw StateError('Identity changed.');
      }
      if (response.statusCode != 200 ||
          (expectedMime != null &&
              response.headers['content-type']?.split(';').first.trim() !=
                  expectedMime)) {
        throw ApiError(
            code: 'MEDIA_UNAVAILABLE',
            message: '',
            statusCode: response.statusCode);
      }
      final bytes = BytesBuilder(copy: false);
      await for (final chunk
          in response.stream.timeout(const Duration(seconds: 60))) {
        if (generation != OfflineIdentity.instance.generation) {
          throw StateError('Identity changed.');
        }
        if (bytes.length + chunk.length > 25 * 1024 * 1024) {
          throw const ApiError(code: 'MEDIA_UNAVAILABLE', message: '');
        }
        bytes.add(chunk);
      }
      return bytes.takeBytes();
    } finally {
      if (_client == null) client.close();
    }
  }

  Future<Map<String, dynamic>> uploadEvidence(
      String nodeId,
      Uint8List bytes,
      String filename,
      Map<String, String> fields,
      String idempotencyKey) async {
    if (bytes.isEmpty || bytes.length > 25 * 1024 * 1024) {
      throw const ApiError(
          code: 'VALIDATION_FAILED', message: 'Invalid evidence size.');
    }
    final baseUri = _validatedBaseUri();
    if (baseUri == null) {
      throw const ApiError(code: 'INSECURE_ENDPOINT', message: '');
    }
    final generation = OfflineIdentity.instance.generation;
    final request = http.MultipartRequest('POST',
        baseUri.replace(path: '${baseUri.path}/runtime/nodes/$nodeId/evidence'))
      ..headers.addAll({
        'Accept': 'application/json',
        'Authorization': 'Bearer ${await token() ?? ''}',
        'X-Device-Id': await deviceId(),
        'Idempotency-Key': idempotencyKey
      })
      ..fields.addAll(fields)
      ..files
          .add(http.MultipartFile.fromBytes('file', bytes, filename: filename));
    if (generation != OfflineIdentity.instance.generation) {
      throw StateError('Identity changed.');
    }
    final client = _client ?? http.Client();
    try {
      final response =
          await client.send(request).timeout(const Duration(seconds: 120));
      final body = await response.stream
          .bytesToString()
          .timeout(const Duration(seconds: 120));
      if (generation != OfflineIdentity.instance.generation) {
        throw StateError('Identity changed.');
      }
      final payload = jsonDecode(body) as Map<String, dynamic>;
      if (response.statusCode < 200 || response.statusCode >= 300) {
        final error = payload['error'] as Map<String, dynamic>? ?? payload;
        throw ApiError(
            code: error['code'] as String? ?? 'UPLOAD_FAILED',
            message: error['message'] as String? ?? '',
            statusCode: response.statusCode);
      }
      return payload['data'] as Map<String, dynamic>;
    } finally {
      if (_client == null) client.close();
    }
  }

  Future<void> clearToken() => _store.delete(_tokenKey);

  Future<String?> token() => _store.read(_tokenKey);

  Future<String> deviceId() => _device.getOrCreate();

  static Uri? _validatedBaseUri() {
    final uri = Uri.tryParse(AppConfig.apiBaseUrl);
    if (uri == null || !AppConfig.isAllowedApiBaseUrl(AppConfig.apiBaseUrl)) {
      return null;
    }
    return uri;
  }

  static bool isNetworkFailure(Object error) =>
      (error is ApiError &&
          ['NETWORK_ERROR', 'NETWORK_UNAVAILABLE'].contains(error.code)) ||
      error is SocketException ||
      error is TimeoutException ||
      error is TlsException ||
      error is http.ClientException;

  static bool _diagnosticRequest(String path) =>
      path == '/auth/login' ||
      path == '/auth/register' ||
      path == '/me' ||
      path.startsWith('/games/') ||
      path == '/games' ||
      path.startsWith('/game-sessions/') ||
      path == '/game-sessions' ||
      path.startsWith('/rooms/') ||
      path.startsWith('/runtime/');

  static String _safeDiagnosticEndpoint(String path) => path
      .split('/')
      .map((segment) =>
          RegExp(r'^[0-9a-fA-F-]{32,36}$').hasMatch(segment) ? ':id' : segment)
      .join('/');

  static String diagnosticFailureKind(Object error) {
    if (error is TimeoutException) return 'timeout';
    if (error is PlatformException) return 'secure_storage_platform';
    if (error is TlsException) return 'tls';
    if (error is SocketException) return 'socket';
    if (error is http.ClientException) {
      final safeType = error.message.toLowerCase();
      if (safeType.contains('handshake') ||
          safeType.contains('certificate') ||
          safeType.contains('tls')) {
        return 'tls';
      }
      if (safeType.contains('socket') ||
          safeType.contains('host lookup') ||
          safeType.contains('connection refused') ||
          safeType.contains('network is unreachable')) {
        return 'socket';
      }
      return 'http_client';
    }
    if (error is FormatException) return 'invalid_json';
    if (error is ApiError) return 'api';
    return 'unexpected';
  }
}
