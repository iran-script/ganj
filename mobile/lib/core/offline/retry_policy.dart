import 'dart:math';

class RetryPolicy {
  const RetryPolicy({this.maxRetries = 8, this.baseDelay = const Duration(seconds: 2), this.maxDelay = const Duration(minutes: 5)});

  final int maxRetries;
  final Duration baseDelay;
  final Duration maxDelay;

  bool canRetry(int retryCount) => retryCount < maxRetries;

  Duration nextDelay(int retryCount, {int jitterSeed = 0}) {
    final exponent = min(retryCount, 8);
    final backoffMs = min(maxDelay.inMilliseconds, baseDelay.inMilliseconds * pow(2, exponent).toInt());
    final jitter = (jitterSeed.abs() % max(1, backoffMs ~/ 4));
    return Duration(milliseconds: min(maxDelay.inMilliseconds, backoffMs + jitter));
  }
}
