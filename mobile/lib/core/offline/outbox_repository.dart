import 'package:uuid/uuid.dart';

import 'local_database.dart';
import 'outbox_action.dart';
import 'offline_identity.dart';

class OutboxRepository {
  OutboxRepository([LocalDatabase? database, OfflineIdentity? identity])
      : _database = database ?? LocalDatabase.instance,
        identity = identity ?? OfflineIdentity.instance;

  final LocalDatabase _database;
  final OfflineIdentity identity;

  Future<OutboxAction> enqueue({
    required String actionType,
    required String sessionId,
    required Map<String, dynamic> payload,
    String? teamId,
    String? nodeInstanceId,
    String? clientActionId,
    DateTime? expiresAt,
  }) async {
    if (!['gps_sample', 'puzzle_submission', 'safe_acknowledgement']
        .contains(actionType)) {
      throw StateError('This action requires an online connection.');
    }
    final context = identity.context(sessionId);
    if (identity.authenticationRequired ||
        context == null ||
        !context.owner.matches(identity.owner) ||
        (teamId != null && teamId != context.teamId)) {
      throw StateError('Verified action ownership is required.');
    }
    if (actionType == 'safe_acknowledgement' && expiresAt == null) {
      throw StateError('Challenge expiry is required.');
    }
    final now = DateTime.now().toUtc();
    final action = OutboxAction(
        ownerUserId: context.owner.userId,
        ownerDeviceUuid: context.owner.deviceUuid,
        playerId: context.playerId,
        expiresAt: expiresAt ??
            now.add(actionType == 'gps_sample'
                ? const Duration(hours: 24)
                : const Duration(days: 7)),
        clientActionId: clientActionId ?? const Uuid().v4(),
        actionType: actionType,
        sessionId: sessionId,
        teamId: context.teamId,
        nodeInstanceId: nodeInstanceId,
        payload: payload,
        sequence: 0,
        createdAt: DateTime.now().toUtc());
    return _database.insertAction(action);
  }

  Future<List<OutboxAction>> ready() async =>
      identity.owner == null ? [] : _database.readyActions(identity.owner!);

  Future<List<OutboxAction>> all() async =>
      identity.owner == null ? [] : _database.allActions(identity.owner!);

  Future<void> acknowledge(OutboxAction action) =>
      _database.updateAction(action, status: 'ACKNOWLEDGED', lastError: null);

  Future<void> retry(OutboxAction action, String error, Duration delay) =>
      _database.updateAction(action,
          status: 'FAILED_RETRYABLE',
          retryCount: action.retryCount + 1,
          nextRetryAt: DateTime.now().toUtc().add(delay),
          lastError: error);

  Future<void> terminal(OutboxAction action, String error) =>
      _database.updateAction(action,
          status: 'FAILED_TERMINAL', nextRetryAt: null, lastError: error);

  Future<void> sending(OutboxAction action) =>
      _database.updateAction(action, status: 'SENDING', nextRetryAt: null);

  Future<void> prune() => _database.prune();

  Future<void> discardAll() async {
    final owner = identity.owner;
    if (owner != null) await _database.discardOutbox(owner);
  }

  Future<void> pause(OutboxAction action, String reason) =>
      _database.updateAction(action, status: 'PAUSED', lastError: reason);

  Future<void> resume(OutboxAction action) =>
      _database.updateAction(action, status: 'PENDING');
}
