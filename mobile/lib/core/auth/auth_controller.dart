import 'package:flutter/foundation.dart';

import '../api/api_client.dart';
import '../errors/api_error.dart';

class AuthController extends ChangeNotifier {
  AuthController(this.api);

  final ApiClient api;
  Map<String, dynamic>? user;
  bool loading = true;
  ApiError? error;

  bool get authenticated => user != null;

  Future<void> restore() async {
    loading = true;
    notifyListeners();
    try {
      if (await api.token() == null) {
        user = null;
      } else {
        user = await api.get('/me', device: true);
      }
      error = null;
    } on ApiError catch (exception) {
      if (exception.code == 'AUTHENTICATION_REQUIRED') {
        await api.clearToken();
        user = null;
      } else {
        error = exception;
      }
    } finally {
      loading = false;
      notifyListeners();
    }
  }

  Future<void> login(String identifier, String password) async {
    loading = true;
    error = null;
    notifyListeners();
    try {
      final isEmail = identifier.contains('@');
      final result = await api.post('/auth/login', body: {(isEmail ? 'email' : 'mobile'): identifier, 'password': password,
        'device_uuid': await api.deviceId(), 'device_name': 'NEXO Player', 'platform': 'flutter', 'app_version': '0.1.0'});
      await api.saveToken(result['token'] as String);
      user = result['user'] as Map<String, dynamic>?;
    } on ApiError catch (exception) {
      error = exception;
      rethrow;
    } finally {
      loading = false;
      notifyListeners();
    }
  }

  Future<void> logout() async {
    try {
      await api.post('/auth/logout', device: true);
    } finally {
      await api.clearToken();
      user = null;
      notifyListeners();
    }
  }
}
