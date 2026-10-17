import '../../core/api/api_client.dart';
import '../../core/auth/user_profile.dart';

class ProfileRepository {
  ProfileRepository(this._api);

  final ApiClient _api;

  Future<UserProfile> load() async =>
      UserProfile.fromJson(await _api.get('/me', device: true));

  Future<UserProfile> update({
    required String name,
    required String email,
    required String mobile,
  }) async {
    final payload =
        UserProfile.patchPayload(name: name, email: email, mobile: mobile);
    return UserProfile.fromJson(await _api.patch('/me', body: payload));
  }
}
