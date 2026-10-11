import 'dart:async';
import 'dart:convert';
import 'dart:io';

import 'package:flutter/foundation.dart';
import 'package:http/http.dart' as http;
import 'package:uuid/uuid.dart';

import '../config/app_config.dart';
import '../errors/api_error.dart';
import '../device/device_identity.dart';
import '../storage/secure_store.dart';
import '../offline/offline_identity.dart';

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

  Future<Map<String, dynamic>> _send(
    String method,
    String path, {
    Map<String, dynamic> body = const {},
    bool device = false,
    bool idempotent = false,
    String? idempotencyKey,
    OfflineOwner? expectedOwner,
  }) async {
    final headers = <String, String>{
      'Accept': 'application/json',
      'Content-Type': 'application/json'
    };
    final token = await _store.read(_tokenKey);
    if (token != null && token.isNotEmpty) {
      headers['Authorization'] = 'Bearer $token';
    }
    final deviceUuid = device ? await _device.getOrCreate() : null;
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
    final authDiagnostic = path == '/auth/login' || path == '/me';
    if (kDebugMode && authDiagnostic) {
      debugPrint(
          'NEXO $method ${AppConfig.apiBaseUrl}$path authorization=${headers.containsKey('Authorization')} device_header=${headers.containsKey('X-Device-Id')}');
    }
    final request =
        http.Request(method, Uri.parse('${AppConfig.apiBaseUrl}$path'))
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
    } finally {
      if (_client == null) client.close();
    }
    if (kDebugMode && authDiagnostic) {
      debugPrint('NEXO $path HTTP ${response.statusCode}');
    }
    final decoded = text.isEmpty
        ? <String, dynamic>{}
        : jsonDecode(text) as Map<String, dynamic>;
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
      if (kDebugMode && authDiagnostic) {
        debugPrint('NEXO $path error.code=${error['code'] ?? 'UNKNOWN_ERROR'}');
      }
      throw ApiError(
          code: error['code'] as String? ?? 'UNKNOWN_ERROR',
          message: error['message'] as String? ?? '',
          statusCode: response.statusCode);
    }
    if (decoded['data'] is List) {
      return {'items': decoded['data'], 'meta': decoded['meta']};
    }
    return (decoded['data'] ?? <String, dynamic>{}) as Map<String, dynamic>;
  }

  Future<void> saveToken(String token) => _store.write(_tokenKey, token);

  Future<void> clearToken() => _store.delete(_tokenKey);

  Future<String?> token() => _store.read(_tokenKey);

  Future<String> deviceId() => _device.getOrCreate();

  static bool isNetworkFailure(Object error) =>
      error is SocketException ||
      error is TimeoutException ||
      error is http.ClientException;
}
