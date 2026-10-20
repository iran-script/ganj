import 'package:flutter/foundation.dart';

import '../api/api_client.dart';
import '../errors/api_error.dart';
import '../offline/outbox_repository.dart';
import '../offline/offline_identity.dart';
import '../offline/local_database.dart';
import 'session_expiry.dart';
import 'user_profile.dart';

class AuthController extends ChangeNotifier {
  AuthController(this.api,
      {OutboxRepository? outbox,
      LocalDatabase? database,
      OfflineIdentity? identity})
      : outbox = outbox ?? OutboxRepository(),
        database = database ?? LocalDatabase.instance,
        identity = identity ?? OfflineIdentity.instance {
    SessionExpiry.instance.addListener(_handleSessionExpiry);
  }

  final ApiClient api;
  final OutboxRepository outbox;
  final LocalDatabase database;
  final OfflineIdentity identity;
  Map<String, dynamic>? user;
  UserProfile? profile;
  bool loading = true;
  bool signingIn = false;
  bool expiring = false;
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
        final typedProfile = UserProfile.fromJson(profile);
        identity.authenticate(typedProfile.id, await api.deviceId());
        user = profile;
        this.profile = typedProfile;
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
        profile = null;
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
      final isEmail = identifier.contains('@');
      await _authenticate('/auth/login', {
        (isEmail ? 'email' : 'mobile'): identifier,
        'password': password,
      });
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
        final safeCode = RegExp(r'^[A-Z0-9_]{1,64}$').hasMatch(error!.code)
            ? error!.code
            : 'UNKNOWN_ERROR';
        debugPrint(
            'NEXO auth login success=false endpoint=/auth/login failure_kind=${ApiClient.diagnosticFailureKind(exception)} status=${error!.statusCode ?? 'none'} code=$safeCode');
      }
      throw error!;
    } finally {
      signingIn = false;
      notifyListeners();
    }
  }

  Future<void> register({
    required String name,
    required String email,
    required String mobile,
    required String password,
  }) async {
    if (signingIn) {
      return;
    }
    signingIn = true;
    error = null;
    notifyListeners();
    try {
      await _authenticate('/auth/register', {
        'name': name.trim(),
        if (email.trim().isNotEmpty) 'email': email.trim(),
        if (mobile.trim().isNotEmpty) 'mobile': mobile.trim(),
        'password': password,
      });
    } catch (exception) {
      error = _toApiError(exception);
      throw error!;
    } finally {
      signingIn = false;
      notifyListeners();
    }
  }

  Future<void> _authenticate(
      String path, Map<String, dynamic> identityFields) async {
    final deviceUuid = await api.deviceId();
    final result = await api.post(path, body: {
      ...identityFields,
      'device_uuid': deviceUuid,
      'device_name': 'NEXO Player',
      'platform': defaultTargetPlatform.name,
      'app_version': '0.1.0',
    });
    final token = result['token'];
    final responseUser = result['user'];
    final device = result['device'];
    if (token is! String ||
        token.isEmpty ||
        responseUser is! Map<String, dynamic> ||
        responseUser['id'] is! String ||
        device is! Map<String, dynamic> ||
        device['device_uuid'] != deviceUuid) {
      if (kDebugMode) {
        final reason = token is! String || token.isEmpty
            ? 'missing_token'
            : responseUser is! Map<String, dynamic>
                ? 'missing_user'
                : responseUser['id'] is! String
                    ? 'invalid_user_id'
                    : device is! Map<String, dynamic>
                        ? 'missing_device'
                        : 'device_uuid_mismatch';
        debugPrint('NEXO auth response failed schema=$reason');
      }
      throw const ApiError(code: 'UNEXPECTED_RESPONSE_SCHEMA', message: '');
    }
    late final UserProfile typedProfile;
    try {
      typedProfile = UserProfile.fromJson(responseUser);
    } on FormatException {
      if (kDebugMode) {
        debugPrint('NEXO auth response failed schema=invalid_user_profile');
      }
      throw const ApiError(code: 'UNEXPECTED_RESPONSE_SCHEMA', message: '');
    } on TypeError {
      if (kDebugMode) {
        debugPrint('NEXO auth response failed schema=invalid_user_profile');
      }
      throw const ApiError(code: 'UNEXPECTED_RESPONSE_SCHEMA', message: '');
    }
    await api.saveToken(token);
    if (await api.token() != token) {
      throw const ApiError(code: 'AUTH_STORAGE_FAILED', message: '');
    }
    identity.authenticate(typedProfile.id, deviceUuid);
    user = responseUser;
    profile = typedProfile;
  }

  void applyProfile(UserProfile saved) {
    profile = saved;
    user = saved.toJson();
    notifyListeners();
  }

  void _handleSessionExpiry() {
    if (authenticated) {
      _expireSession();
    }
  }

  Future<void> _expireSession() async {
    if (expiring) {
      return;
    }
    expiring = true;
    try {
      await api.clearToken();
      await database.clearCache();
    } finally {
      identity.clear();
      user = null;
      profile = null;
      error = const ApiError(code: 'AUTHENTICATION_REQUIRED', message: '');
      expiring = false;
      notifyListeners();
    }
  }

  ApiError _toApiError(Object exception) => exception is ApiError
      ? exception
      : ApiError(
          code: ApiClient.isNetworkFailure(exception)
              ? 'NETWORK_ERROR'
              : 'AUTH_STORAGE_FAILED',
          message: '',
        );

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
      profile = null;
      notifyListeners();
    }
    return true;
  }

  @override
  void dispose() {
    SessionExpiry.instance.removeListener(_handleSessionExpiry);
    super.dispose();
  }
}
