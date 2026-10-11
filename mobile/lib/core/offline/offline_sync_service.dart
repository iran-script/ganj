import '../api/api_client.dart';
import '../errors/api_error.dart';
import 'offline_identity.dart';
import 'outbox_action.dart';
import 'outbox_repository.dart';
import 'retry_policy.dart';
import 'sync_state.dart';

class OfflineSyncService {
  OfflineSyncService(
      {ApiClient? api,
      OutboxRepository? outbox,
      RetryPolicy? retryPolicy,
      SyncStatus? status,
      OfflineIdentity? identity})
      : _api = api ?? ApiClient(),
        _outbox = outbox ?? OutboxRepository(),
        _retryPolicy = retryPolicy ?? const RetryPolicy(),
        identity = identity ?? OfflineIdentity.instance,
        status = status ?? SyncStatus();

  final ApiClient _api;
  final OutboxRepository _outbox;
  final RetryPolicy _retryPolicy;
  final OfflineIdentity identity;
  final SyncStatus status;
  static bool _running = false;

  Future<void> sync() async {
    if (_running) return;
    final owner = identity.owner;
    if (owner == null || identity.authenticationRequired) {
      status.setState(SyncState.syncError, message: 'AUTHENTICATION_REQUIRED');
      return;
    }
    _running = true;
    final generation = identity.generation;
    try {
      final actions = (await _outbox.all())
          .where((a) => !['ACKNOWLEDGED', 'FAILED_TERMINAL'].contains(a.status))
          .toList();
      status.setState(SyncState.syncing, pending: actions.length);
      for (final action in actions) {
        if (generation != identity.generation ||
            !action.belongsTo(identity.owner)) {
          return;
        }
        final now = DateTime.now().toUtc();
        if (action.expiresAt == null || !now.isBefore(action.expiresAt!)) {
          await _outbox.terminal(action, 'EXPIRED_ACTION');
          continue;
        }
        if (action.nextRetryAt?.isAfter(now) ?? false) break;
        var responseRecorded = false;
        try {
          final me = await _api.get('/me', device: true);
          if (me['id'] != owner.userId ||
              await _api.deviceId() != owner.deviceUuid ||
              generation != identity.generation) {
            throw const ApiError(
                code: 'AUTHENTICATION_REQUIRED',
                message: 'Replay owner changed.');
          }
          final session = await _api.get('/game-sessions/${action.sessionId}',
              device: true);
          if (['FINISHED', 'CANCELLED'].contains(session['status'])) {
            throw ApiError(
                code: 'SESSION_${session['status']}',
                message: 'Session ended.');
          }
          if (session['status'] != 'RUNNING') {
            throw const ApiError(
                code: 'SESSION_PAUSED', message: 'Session is not running.');
          }
          final projection = await _api.get(
              '/game-sessions/${action.sessionId}/players/me/runtime',
              device: true);
          if (generation != identity.generation) return;
          final context = identity.bind(action.sessionId, projection);
          if (!action.matchesContext(context)) {
            await _outbox.terminal(action, 'OWNERSHIP_CONTEXT_CHANGED');
            continue;
          }
          final player = projection['player'] as Map;
          if (player['participation_status'] == 'DISQUALIFIED') {
            throw const ApiError(
                code: 'PLAYER_DISQUALIFIED', message: 'Player disqualified.');
          }
          final devices = await _api.get('/devices?limit=100', device: true);
          final bound = (devices['items'] as List? ?? const [])
              .whereType<Map>()
              .any((entry) =>
                  entry['device_uuid'] == owner.deviceUuid &&
                  entry['id'] == player['device_id'] &&
                  entry['revoked_at'] == null);
          if (!bound) {
            throw const ApiError(
                code: 'DEVICE_NOT_BOUND',
                message: 'Server-side device recovery is required.');
          }
          if (['COMPLETED', 'CANCELLED'].contains(projection['status'])) {
            throw const ApiError(
                code: 'SESSION_FINISHED', message: 'Runtime ended.');
          }
          if (projection['status'] != 'ACTIVE') {
            throw const ApiError(
                code: 'SESSION_PAUSED', message: 'Runtime is paused.');
          }
          if (action.actionType == 'puzzle_submission') {
            final nodes = (projection['nodes'] as List).cast<Map>();
            final node = nodes
                .where((n) => n['id'] == action.nodeInstanceId)
                .firstOrNull;
            if (node == null ||
                node['status'] != 'ACTIVE' ||
                !['Puzzle', 'Password', 'Text Answer', 'Multiple Choice']
                    .contains(node['node_type'])) {
              await _outbox.terminal(action, 'NODE_NO_LONGER_ACTIVE');
              continue;
            }
          } else if (action.actionType == 'safe_acknowledgement') {
            final challenge = await _api.get(
                '/live-challenges/${action.payload['challenge_id']}',
                device: true);
            final expires =
                DateTime.tryParse(challenge['expires_at'] as String? ?? '');
            if (expires == null ||
                !now.isBefore(expires) ||
                ['COMPLETED', 'EXPIRED', 'CANCELLED']
                    .contains(challenge['status'])) {
              await _outbox.terminal(action, 'CHALLENGE_EXPIRED');
              continue;
            }
          }
          if (generation != identity.generation) return;
          final path = switch (action.actionType) {
            'gps_sample' => '/locations',
            'puzzle_submission' =>
              '/runtime/nodes/${action.nodeInstanceId}/submit',
            'safe_acknowledgement' =>
              '/live-challenges/${action.payload['challenge_id']}/ack',
            _ => null,
          };
          if (path == null) {
            await _outbox.terminal(action, 'ONLINE_REQUIRED');
            continue;
          }
          await _outbox.sending(action);
          final result = await _api.post(path,
              body: action.payload,
              device: true,
              idempotent: true,
              idempotencyKey: action.clientActionId,
              expectedOwner: owner);
          if (result['accepted'] == false) {
            await _outbox.terminal(
                action, result['result'] as String? ?? 'SERVER_REJECTED');
          } else {
            await _outbox.acknowledge(action);
          }
          responseRecorded = true;
          // Reconcile from the API; a cached projection never authorizes replay.
          await _api.get(
              '/game-sessions/${action.sessionId}/players/me/runtime',
              device: true);
        } on ApiError catch (error) {
          if (responseRecorded) {
            status.setState(SyncState.syncError, message: error.code);
            return;
          }
          if (error.code == 'AUTHENTICATION_REQUIRED') {
            identity.pause();
            await _outbox.pause(action, error.code);
            status.setState(SyncState.syncError, message: error.code);
            return;
          }
          if (error.code == 'DEVICE_NOT_BOUND' ||
              error.code == 'SESSION_PAUSED') {
            for (final pending
                in actions.where((a) => a.sessionId == action.sessionId)) {
              if (pending.sequence >= action.sequence) {
                await _outbox.pause(pending, error.code);
              }
            }
            status.setState(SyncState.syncError, message: error.code);
            return;
          }
          if (['PLAYER_DISQUALIFIED', 'SESSION_FINISHED', 'SESSION_CANCELLED']
              .contains(error.code)) {
            for (final pending
                in actions.where((a) => a.sessionId == action.sessionId)) {
              if (pending.sequence >= action.sequence) {
                await _outbox.terminal(pending, error.code);
              }
            }
            status.setState(SyncState.syncError, message: error.code);
            return;
          }
          if (_terminalCodes.contains(error.code)) {
            await _outbox.terminal(action, error.code);
            continue;
          }
          await _retry(action, error.code);
          return;
        } catch (error) {
          if (responseRecorded) {
            status.setState(SyncState.syncError, message: 'REFRESH_REQUIRED');
            return;
          }
          await _retry(
              action,
              ApiClient.isNetworkFailure(error)
                  ? 'NETWORK_UNAVAILABLE'
                  : 'RECONCILIATION_FAILED');
          return;
        }
      }
      if (actions.isNotEmpty) {
        final results = await _outbox.all();
        final changed = results.where((item) => actions
            .any((original) => original.clientActionId == item.clientActionId));
        if (changed.any((item) => item.status == 'FAILED_TERMINAL')) {
          status.outcome =
              'برخی اقدام‌ها منقضی یا رد شدند و قابل ارسال نیستند.';
        } else if (changed.every((item) => item.status == 'ACKNOWLEDGED')) {
          status.outcome = 'همگام‌سازی انجام شد';
        }
      }
      await _outbox.prune();
      final pending = (await _outbox.all())
          .where((a) => !['ACKNOWLEDGED', 'FAILED_TERMINAL'].contains(a.status))
          .length;
      status.setState(pending == 0 ? SyncState.online : SyncState.actionPending,
          pending: pending);
    } finally {
      _running = false;
    }
  }

  Future<void> _retry(OutboxAction action, String code) async {
    if (!_retryPolicy.canRetry(action.retryCount)) {
      await _outbox.terminal(action, 'RETRY_EXHAUSTED:$code');
    } else {
      await _outbox.retry(
          action,
          code,
          _retryPolicy.nextDelay(action.retryCount,
              jitterSeed: action.clientActionId.hashCode));
    }
    status.setState(SyncState.syncError, message: code);
  }

  static const _terminalCodes = {
    'NODE_ALREADY_COMPLETED',
    'NODE_NO_LONGER_ACTIVE',
    'IDEMPOTENCY_CONFLICT',
    'EXPIRED_ACTION',
    'ENTITLEMENT_UNAVAILABLE',
    'ACTION_NOT_ALLOWED',
    'VALIDATION_FAILED',
    'RESOURCE_NOT_FOUND',
    'STATE_TRANSITION_INVALID',
  };
}
