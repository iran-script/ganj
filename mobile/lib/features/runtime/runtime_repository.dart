import 'package:uuid/uuid.dart';

import '../../core/api/api_client.dart';
import '../../core/offline/outbox_repository.dart';
import '../../core/offline/safe_cache.dart';

class RuntimeRepository {
  RuntimeRepository([ApiClient? api, OutboxRepository? outbox, SafeCache? cache])
      : _api = api ?? ApiClient(),
        _outbox = outbox ?? OutboxRepository(),
        _cache = cache ?? SafeCache();

  final ApiClient _api;
  final OutboxRepository _outbox;
  final SafeCache _cache;

  Future<Map<String, dynamic>> projection(String sessionId) async {
    try {
      final result = await _api.get('/game-sessions/$sessionId/players/me/runtime', device: true);
      await _cache.saveRuntime(sessionId, result);
      return result;
    } catch (error) {
      final cached = await _cache.runtime(sessionId);
      if (cached != null && ApiClient.isNetworkFailure(error)) return cached;
      rethrow;
    }
  }

  Future<Map<String, dynamic>> start(String nodeId, {String? key}) => _api.post('/runtime/nodes/$nodeId/start', device: true, idempotent: true, idempotencyKey: key ?? const Uuid().v4());

  Future<Map<String, dynamic>> submit(String nodeId, Map<String, dynamic> evidence, {String? key}) => _api.post('/runtime/nodes/$nodeId/submit', body: evidence, device: true, idempotent: true, idempotencyKey: key ?? const Uuid().v4());

  Future<Map<String, dynamic>> complete(String nodeId, Map<String, dynamic> evidence, {String? key}) => _api.post('/runtime/nodes/$nodeId/complete', body: evidence, device: true, idempotent: true, idempotencyKey: key ?? const Uuid().v4());

  Future<Map<String, dynamic>> submitWithOfflineFallback({required String sessionId, required String nodeId, required Map<String, dynamic> evidence, required bool delayedValidationAllowed, String? teamId}) async {
    final key = const Uuid().v4();
    try {
      return await submit(nodeId, evidence, key: key);
    } catch (error) {
      if (!delayedValidationAllowed || !ApiClient.isNetworkFailure(error)) rethrow;
      final action = await _outbox.enqueue(actionType: 'puzzle_submission', sessionId: sessionId, teamId: teamId, nodeInstanceId: nodeId, payload: evidence, clientActionId: key);
      return {'pending_verification': true, 'client_action_id': action.clientActionId};
    }
  }
}
