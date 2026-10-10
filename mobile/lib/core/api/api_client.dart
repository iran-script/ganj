import 'dart:async';
import 'dart:convert';
import 'dart:io';

import 'package:http/http.dart' as http;
import 'package:uuid/uuid.dart';

import '../config/app_config.dart';
import '../errors/api_error.dart';
import '../device/device_identity.dart';
import '../storage/secure_store.dart';

class ApiClient {
  ApiClient({SecureStore? store})
      : _store = store ?? SecureStore(),
        _device = DeviceIdentity(store ?? SecureStore());

  static const _tokenKey = 'nexo.access_token';
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
  }) async {
    return _send('POST', path, body: body, device: device, idempotent: idempotent, idempotencyKey: idempotencyKey);
  }

  Future<Map<String, dynamic>> _send(
    String method,
    String path, {
    Map<String, dynamic> body = const {},
    bool device = false,
    bool idempotent = false,
    String? idempotencyKey,
  }) async {
    final headers = <String, String>{'Accept': 'application/json', 'Content-Type': 'application/json'};
    final token = await _store.read(_tokenKey);
    if (token != null && token.isNotEmpty) {
      headers['Authorization'] = 'Bearer $token';
    }
    if (device) {
      headers['X-Device-Id'] = await _device.getOrCreate();
    }
    if (idempotent) {
      headers['Idempotency-Key'] = idempotencyKey ?? const Uuid().v4();
    }
    final request = http.Request(method, Uri.parse('${AppConfig.apiBaseUrl}$path'))..headers.addAll(headers);
    if (method != 'GET') {
      request.body = jsonEncode(body);
    }
    final response = await http.Client().send(request).timeout(const Duration(seconds: 20));
    final text = await response.stream.bytesToString();
    final decoded = text.isEmpty ? <String, dynamic>{} : jsonDecode(text) as Map<String, dynamic>;
    final nestedData = decoded['data'] is Map<String, dynamic> ? decoded['data'] as Map<String, dynamic> : null;
    if (response.statusCode < 200 || response.statusCode >= 300 || decoded['error'] != null || nestedData?['error'] != null) {
      final error = (decoded['error'] ?? nestedData?['error'] ?? <String, dynamic>{}) as Map<String, dynamic>;
      throw ApiError(code: error['code'] as String? ?? 'UNKNOWN_ERROR', message: error['message'] as String? ?? '', statusCode: response.statusCode);
    }
    return (decoded['data'] ?? <String, dynamic>{}) as Map<String, dynamic>;
  }

  Future<void> saveToken(String token) => _store.write(_tokenKey, token);

  Future<void> clearToken() => _store.delete(_tokenKey);

  Future<String?> token() => _store.read(_tokenKey);

  Future<String> deviceId() => _device.getOrCreate();

  static bool isNetworkFailure(Object error) => error is SocketException || error is TimeoutException || error is http.ClientException;
}
