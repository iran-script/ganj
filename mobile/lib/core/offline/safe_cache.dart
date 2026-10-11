import 'local_database.dart';
import 'offline_identity.dart';

class SafeCache {
  SafeCache([LocalDatabase? database, OfflineIdentity? identity])
      : _database = database ?? LocalDatabase.instance,
        identity = identity ?? OfflineIdentity.instance;

  final LocalDatabase _database;
  final OfflineIdentity identity;

  Future<void> saveRuntime(
      String sessionId, Map<String, dynamic> projection) async {
    final context = identity.bind(sessionId, projection);
    await _database.saveCache('runtime:v2:${context.key}', projection,
        revision: projection['revision']?.toString());
  }

  Future<Map<String, dynamic>?> runtime(String sessionId) async {
    final context = identity.context(sessionId);
    if (context == null || !context.owner.matches(identity.owner)) return null;
    return _database.readCache('runtime:v2:${context.key}');
  }
}
