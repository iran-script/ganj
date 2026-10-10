import 'package:uuid/uuid.dart';

import 'local_database.dart';
import 'outbox_action.dart';

class OutboxRepository {
  OutboxRepository([LocalDatabase? database]) : _database = database ?? LocalDatabase.instance;

  final LocalDatabase _database;

  Future<OutboxAction> enqueue({
    required String actionType,
    required String sessionId,
    required Map<String, dynamic> payload,
    String? teamId,
    String? nodeInstanceId,
    String? clientActionId,
  }) async {
    final action = OutboxAction(clientActionId: clientActionId ?? const Uuid().v4(), actionType: actionType, sessionId: sessionId,
        teamId: teamId, nodeInstanceId: nodeInstanceId, payload: payload, sequence: await _database.nextSequence(sessionId), createdAt: DateTime.now().toUtc());
    await _database.insertAction(action);
    return action;
  }

  Future<List<OutboxAction>> ready() => _database.readyActions();

  Future<List<OutboxAction>> all() => _database.allActions();

  Future<void> acknowledge(OutboxAction action) => _database.updateAction(action.clientActionId, status: 'ACKNOWLEDGED', lastError: null);

  Future<void> retry(OutboxAction action, String error, Duration delay) => _database.updateAction(action.clientActionId, status: 'FAILED_RETRYABLE', retryCount: action.retryCount + 1, nextRetryAt: DateTime.now().toUtc().add(delay), lastError: error);

  Future<void> terminal(OutboxAction action, String error) => _database.updateAction(action.clientActionId, status: 'FAILED_TERMINAL', nextRetryAt: null, lastError: error);

  Future<void> sending(OutboxAction action) => _database.updateAction(action.clientActionId, status: 'SENDING', nextRetryAt: null);

  Future<void> prune() => _database.prune();

  Future<void> discardAll() => _database.discardOutbox();
}
