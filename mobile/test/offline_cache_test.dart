import 'package:flutter_test/flutter_test.dart';
import 'package:nexo_player/core/auth/auth_controller.dart';
import 'package:nexo_player/core/offline/local_database.dart';
import 'package:nexo_player/core/offline/offline_identity.dart';
import 'package:nexo_player/core/offline/safe_cache.dart';
import 'offline_replay_test.dart' show MemoryOutbox, ReplayApi, projection;

void main() {
  test('cache namespaces isolate account, device, session, team and player',
      () async {
    final identity = OfflineIdentity()..authenticate('user-a', 'device-a');
    final database = MemoryCache();
    final cache = SafeCache(database, identity);
    await cache.saveRuntime('session-a', projection());
    expect(await cache.runtime('session-a'), isNotNull);
    expect(await cache.runtime('other-session'), isNull);
    identity.authenticate('user-b', 'device-a');
    identity.bind('session-a', projection());
    expect(await cache.runtime('session-a'), isNull);
    identity.authenticate('user-a', 'device-b');
    identity.bind('session-a', projection());
    expect(await cache.runtime('session-a'), isNull);
    identity.authenticate('user-a', 'device-a');
    identity.bind('session-a', projection(playerId: 'player-b'));
    expect(await cache.runtime('session-a'), isNull);
    identity.bind('session-a', {...projection(), 'team_id': 'team-b'});
    expect(await cache.runtime('session-a'), isNull);
  });

  test('logout clears credentials and private cache before another login',
      () async {
    final identity = OfflineIdentity()..authenticate('user-a', 'device-a');
    final database = MemoryCache();
    final cache = SafeCache(database, identity);
    await cache.saveRuntime('session-a', projection());
    final api = AuthApi();
    final auth = AuthController(api,
        outbox: MemoryOutbox(identity), database: database, identity: identity);
    auth.user = {'id': 'user-a'};

    expect(await auth.logout(), isTrue);
    expect(api.savedToken, isNull);
    expect(identity.owner, isNull);
    expect(database.values, isEmpty);
    await auth.login('second@example.test', 'test-password');

    expect(identity.owner!.userId, 'user-b');
    expect(await cache.runtime('session-a'), isNull);
    expect(auth.user!['id'], 'user-b');
  });
}

class MemoryCache extends LocalDatabase {
  final values = <String, Map<String, dynamic>>{};
  @override
  Future<void> saveCache(String key, Map<String, dynamic> payload,
      {String? revision}) async {
    values[key] = payload;
  }

  @override
  Future<Map<String, dynamic>?> readCache(String key) async => values[key];
  @override
  Future<void> clearCache() async => values.clear();
}

class AuthApi extends ReplayApi {
  String? savedToken = 'first-token';
  @override
  Future<String?> token() async => savedToken;
  @override
  Future<void> saveToken(String token) async {
    savedToken = token;
  }

  @override
  Future<void> clearToken() async {
    savedToken = null;
  }

  @override
  Future<Map<String, dynamic>> post(String path,
          {Map<String, dynamic> body = const {},
          bool device = false,
          bool idempotent = false,
          String? idempotencyKey,
          OfflineOwner? expectedOwner}) async =>
      path == '/auth/login'
          ? {
              'token': 'second-token',
              'user': {'id': 'user-b'},
              'device': {'device_uuid': await deviceId()}
            }
          : {};
}
