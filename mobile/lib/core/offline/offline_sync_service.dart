import '../api/api_client.dart';
import '../errors/api_error.dart';
import 'outbox_action.dart';
import 'outbox_repository.dart';
import 'retry_policy.dart';
import 'sync_state.dart';

class OfflineSyncService {
  OfflineSyncService({ApiClient? api, OutboxRepository? outbox, RetryPolicy? retryPolicy, SyncStatus? status})
      : _api = api ?? ApiClient(),
        _outbox = outbox ?? OutboxRepository(),
        _retryPolicy = retryPolicy ?? const RetryPolicy(),
        status = status ?? SyncStatus();

  final ApiClient _api;
  final OutboxRepository _outbox;
  final RetryPolicy _retryPolicy;
  final SyncStatus status;

  Future<void> sync() async {
    final actions = await _outbox.ready();
    if (actions.isEmpty) {
      status.setState(SyncState.online, pending: 0);
      return;
    }
    status.setState(SyncState.syncing, pending: actions.length);
    for (final action in actions) {
      if (action.actionType == 'gps_sample') {
        await _sendGps(action);
      } else {
        await _sendAction(action);
      }
    }
    final remaining = (await _outbox.ready()).length;
    status.setState(remaining == 0 ? SyncState.online : SyncState.actionPending, pending: remaining);
  }

  Future<void> _sendGps(OutboxAction action) async {
    await _send(action, () => _api.post('/locations', body: action.payload, device: true, idempotent: true, idempotencyKey: action.clientActionId));
  }

  Future<void> _sendAction(OutboxAction action) async {
    final path = switch (action.actionType) {
      'puzzle_submission' => '/runtime/nodes/${action.nodeInstanceId}/submit',
      'safe_acknowledgement' => '/live-challenges/${action.payload['challenge_id']}/ack',
      _ => throw StateError('Unsupported queued action type: ${action.actionType}'),
    };
    await _send(action, () => _api.post(path, body: action.payload, device: true, idempotent: true, idempotencyKey: action.clientActionId));
  }

  Future<void> _send(OutboxAction action, Future<Map<String, dynamic>> Function() request) async {
    await _outbox.sending(action);
    try {
      await request();
      await _outbox.acknowledge(action);
    } on ApiError catch (error) {
      if (_terminalCodes.contains(error.code) || !_retryPolicy.canRetry(action.retryCount)) {
        await _outbox.terminal(action, error.code);
        return;
      }
      await _outbox.retry(action, error.code, _retryPolicy.nextDelay(action.retryCount, jitterSeed: action.clientActionId.hashCode));
      status.setState(SyncState.syncError, message: error.userMessage);
    } catch (error) {
      if (!_retryPolicy.canRetry(action.retryCount)) {
        await _outbox.terminal(action, 'NETWORK_RETRY_EXHAUSTED');
        return;
      }
      await _outbox.retry(action, 'NETWORK_UNAVAILABLE', _retryPolicy.nextDelay(action.retryCount, jitterSeed: action.clientActionId.hashCode));
      status.setState(SyncState.reconnecting, message: 'شبکه در دسترس نیست. تلاش دوباره انجام می‌شود.');
    }
  }

  static const _terminalCodes = {
    'NODE_ALREADY_COMPLETED',
    'NODE_NO_LONGER_ACTIVE',
    'SESSION_PAUSED',
    'SESSION_FINISHED',
    'PLAYER_DISQUALIFIED',
    'DEVICE_NOT_BOUND',
    'IDEMPOTENCY_CONFLICT',
    'EXPIRED_ACTION',
    'ENTITLEMENT_UNAVAILABLE',
    'ACTION_NOT_ALLOWED',
    'VALIDATION_FAILED',
  };
}
