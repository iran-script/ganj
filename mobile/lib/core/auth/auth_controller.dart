import 'package:flutter/foundation.dart';

import '../api/api_client.dart';
import '../errors/api_error.dart';
import '../offline/outbox_repository.dart';
import '../offline/offline_identity.dart';
import '../offline/local_database.dart';

class AuthController extends ChangeNotifier {
  AuthController(this.api,
      {OutboxRepository? outbox,
      LocalDatabase? database,
      OfflineIdentity? identity})
      : outbox = outbox ?? OutboxRepository(),
        database = database ?? LocalDatabase.instance,
        identity = identity ?? OfflineIdentity.instance;

  final ApiClient api;
  final OutboxRepository outbox;
  final LocalDatabase database;
  final OfflineIdentity identity;
  Map<String, dynamic>? user;
  bool loading = true;
  bool signingIn = false;
  ApiError? error;

  bool get authenticated => user != null;

  Future<void> restore() async {
    loading = true;
    notifyListeners();
    try {
      if (await api.token() == null) {
        user = null;
        identity.clear();
      } else {
        final profile = await api.get('/me', device: true);
        if (profile['id'] is! String) {
          throw const ApiError(code: 'INVALID_RESPONSE', message: '');
        }
        identity.authenticate(profile['id'] as String, await api.deviceId());
        user = profile;
      }
      error = null;
    } catch (failure) {
      final exception = failure is ApiError
          ? failure
          : ApiError(
              code: ApiClient.isNetworkFailure(failure)
                  ? 'NETWORK_ERROR'
                  : 'AUTH_STORAGE_FAILED',
              message: '');
      error = exception;
      if (kDebugMode) {
        debugPrint('NEXO auth restore error.code=${exception.code}');
      }
      if (exception.code == 'AUTHENTICATION_REQUIRED') {
        await api.clearToken();
        user = null;
        identity.clear();
      } else {
        error = exception;
      }
    } finally {
      loading = false;
      notifyListeners();
    }
  }

  Future<void> login(String identifier, String password) async {
    if (signingIn) return;
    signingIn = true;
    error = null;
    notifyListeners();
    try {
      final deviceUuid = await api.deviceId();
      final isEmail = identifier.contains('@');
      final result = await api.post('/auth/login', body: {
        (isEmail ? 'email' : 'mobile'): identifier,
        'password': password,
        'device_uuid': deviceUuid,
        'device_name': 'NEXO Player',
        'platform': defaultTargetPlatform.name,
        'app_version': '0.1.0'
      });
      final token = result['token'];
      final profile = result['user'];
      final device = result['device'];
      if (token is! String ||
          token.isEmpty ||
          profile is! Map<String, dynamic> ||
          profile['id'] is! String ||
          device is! Map<String, dynamic> ||
          device['device_uuid'] != deviceUuid) {
        throw const ApiError(code: 'INVALID_RESPONSE', message: '');
      }
      await api.saveToken(token);
      if (kDebugMode) debugPrint('NEXO auth token saved=true');
      final readable = await api.token() == token;
      if (kDebugMode) debugPrint('NEXO auth token readable=$readable');
      if (!readable) {
        throw const ApiError(code: 'AUTH_STORAGE_FAILED', message: '');
      }
      identity.authenticate(profile['id'] as String, deviceUuid);
      user = profile;
      if (kDebugMode) debugPrint('NEXO auth login success=true');
    } catch (exception) {
      error = exception is ApiError
          ? exception
          : ApiError(
              code: ApiClient.isNetworkFailure(exception)
                  ? 'NETWORK_ERROR'
                  : 'AUTH_STORAGE_FAILED',
              message: '',
            );
      if (error!.code == 'AUTHENTICATION_REQUIRED') {
        error = ApiError(
            code: 'INVALID_CREDENTIALS',
            message: '',
            statusCode: error!.statusCode);
      }
      if (kDebugMode) {
        debugPrint('NEXO auth login success=false error.code=${error!.code}');
      }
      throw error!;
    } finally {
      signingIn = false;
      notifyListeners();
    }
  }

  Future<bool> logout({bool discardPending = false}) async {
    final pending = (await outbox.all())
        .where((action) =>
            !['ACKNOWLEDGED', 'FAILED_TERMINAL'].contains(action.status))
        .isNotEmpty;
    if (pending && !discardPending) return false;
    try {
      await api.post('/auth/logout', device: true);
    } finally {
      if (discardPending) await outbox.discardAll();
      identity.clear();
      await api.clearToken();
      await database.clearCache();
      user = null;
      notifyListeners();
    }
    return true;
  }
}
