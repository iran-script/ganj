import 'local_database.dart';

class SafeCache {
  SafeCache([LocalDatabase? database]) : _database = database ?? LocalDatabase.instance;

  final LocalDatabase _database;

  Future<void> saveRuntime(String sessionId, Map<String, dynamic> projection) => _database.saveCache('runtime:$sessionId', projection, revision: projection['revision']?.toString());

  Future<Map<String, dynamic>?> runtime(String sessionId) => _database.readCache('runtime:$sessionId');
}
