import 'package:uuid/uuid.dart';

import '../../core/api/api_client.dart';

class RuntimeRepository {
  RuntimeRepository([ApiClient? api]) : _api = api ?? ApiClient();

  final ApiClient _api;

  Future<Map<String, dynamic>> projection(String sessionId) => _api.get('/game-sessions/$sessionId/players/me/runtime', device: true);

  Future<Map<String, dynamic>> start(String nodeId, {String? key}) => _api.post('/runtime/nodes/$nodeId/start', device: true, idempotent: true, idempotencyKey: key ?? const Uuid().v4());

  Future<Map<String, dynamic>> submit(String nodeId, Map<String, dynamic> evidence, {String? key}) => _api.post('/runtime/nodes/$nodeId/submit', body: evidence, device: true, idempotent: true, idempotencyKey: key ?? const Uuid().v4());

  Future<Map<String, dynamic>> complete(String nodeId, Map<String, dynamic> evidence, {String? key}) => _api.post('/runtime/nodes/$nodeId/complete', body: evidence, device: true, idempotent: true, idempotencyKey: key ?? const Uuid().v4());
}
