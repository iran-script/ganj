import 'package:uuid/uuid.dart';
import 'package:flutter/foundation.dart';

import '../../core/api/api_client.dart';
import '../../core/offline/outbox_repository.dart';
import '../../core/offline/safe_cache.dart';
import '../../core/offline/offline_identity.dart';

class RuntimeRepository {
  RuntimeRepository(
      [ApiClient? api, OutboxRepository? outbox, SafeCache? cache])
      : _api = api ?? ApiClient(),
        _outbox = outbox ?? OutboxRepository(),
        _cache = cache ?? SafeCache();

  final ApiClient _api;
  final OutboxRepository _outbox;
  final SafeCache _cache;

  Future<Map<String, dynamic>> projection(String sessionId) async {
    final generation = OfflineIdentity.instance.generation;
    try {
      final result = await _api.get(
        '/game-sessions/$sessionId/players/me/runtime',
        device: true,
      );
      if (generation != OfflineIdentity.instance.generation) {
        throw StateError('Identity changed.');
      }
      try {
        final state =
            await _api.get('/game-sessions/$sessionId/state', device: true);
        result['session_status'] = state['status'];
      } catch (error) {
        if (kDebugMode) {
          debugPrint(
              'NEXO optional session status unavailable kind=${ApiClient.diagnosticFailureKind(error)}');
        }
      }
      await _cache.saveRuntime(sessionId, result);
      return result;
    } catch (error) {
      if (generation != OfflineIdentity.instance.generation) rethrow;
      final cached = await _cache.runtime(sessionId);
      if (cached != null && ApiClient.isNetworkFailure(error)) {
        return {...cached, '_cached': true};
      }
      rethrow;
    }
  }

  Future<Map<String, dynamic>> start(String nodeId, {String? key}) =>
      _api.post('/runtime/nodes/$nodeId/start',
          device: true,
          idempotent: true,
          idempotencyKey: key ?? const Uuid().v4());

  Future<Map<String, dynamic>> submit(
          String nodeId, Map<String, dynamic> evidence, {String? key}) =>
      _api.post('/runtime/nodes/$nodeId/submit',
          body: evidence,
          device: true,
          idempotent: true,
          idempotencyKey: key ?? const Uuid().v4());

  Future<Map<String, dynamic>> complete(
          String nodeId, Map<String, dynamic> evidence, {String? key}) =>
      _api.post('/runtime/nodes/$nodeId/complete',
          body: evidence,
          device: true,
          idempotent: true,
          idempotencyKey: key ?? const Uuid().v4());

  Future<Map<String, dynamic>> submitWithOfflineFallback(
      {required String sessionId,
      required String nodeId,
      required Map<String, dynamic> evidence,
      required bool delayedValidationAllowed,
      required String nodeType,
      String? teamId,
      String? idempotencyKey}) async {
    final key = idempotencyKey ?? const Uuid().v4();
    final generation = OfflineIdentity.instance.generation;
    try {
      return await submit(nodeId, evidence, key: key);
    } catch (error) {
      if (generation != OfflineIdentity.instance.generation ||
          !['Puzzle', 'Password', 'Text Answer', 'Multiple Choice']
              .contains(nodeType) ||
          !delayedValidationAllowed ||
          !ApiClient.isNetworkFailure(error)) {
        rethrow;
      }
      final action = await _outbox.enqueue(
          actionType: 'puzzle_submission',
          sessionId: sessionId,
          teamId: teamId,
          nodeInstanceId: nodeId,
          payload: evidence,
          clientActionId: key);
      return {
        'pending_verification': true,
        'client_action_id': action.clientActionId
      };
    }
  }
}
