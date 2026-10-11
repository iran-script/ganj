import 'package:flutter_test/flutter_test.dart';
import 'package:nexo_player/core/offline/outbox_action.dart';
import 'package:nexo_player/core/offline/operation_classification.dart';
import 'package:nexo_player/core/offline/retry_policy.dart';

void main() {
  test('classifies sensitive actions as online required', () {
    expect(classifyAction('qr_scan'), OperationClass.onlineRequired);
    expect(classifyAction('score_adjustment'), OperationClass.onlineRequired);
    expect(classifyAction('gps_sample'), OperationClass.offlineQueueable);
  });

  test('delayed puzzle submission is online preferred with queue fallback', () {
    expect(classifyAction('puzzle_submission'),
        OperationClass.onlinePreferredWithQueueFallback);
    expect(classifyAction('runtime_projection'), OperationClass.cacheableRead);
  });

  test('retry policy is bounded and increases delay', () {
    const policy = RetryPolicy();
    expect(policy.canRetry(0), isTrue);
    expect(policy.nextDelay(2, jitterSeed: 1),
        greaterThan(policy.nextDelay(1, jitterSeed: 1)));
    expect(policy.nextDelay(99), lessThanOrEqualTo(policy.maxDelay));
  });

  test('outbox action preserves the stable client action id', () {
    final action = OutboxAction(
      clientActionId: 'stable-action-id',
      actionType: 'puzzle_submission',
      sessionId: 'session-1',
      nodeInstanceId: 'node-1',
      payload: const {'answer': 'pending'},
      sequence: 1,
      createdAt: DateTime.utc(2026, 1, 1),
    );

    expect(OutboxAction.fromMap(action.toMap()).clientActionId,
        'stable-action-id');
    expect(
        action.copyWith(retryCount: 1).clientActionId, action.clientActionId);
  });
}
