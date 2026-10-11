import 'package:flutter/material.dart';

import '../../app/nexo_theme.dart';

/// Displays only the elapsed time returned by Runtime. The enclosing screen
/// refreshes it from the server; reopening a screen cannot restart the clock.
class ServerTimer extends StatelessWidget {
  const ServerTimer({required this.runtime, super.key});
  final Map<String, dynamic> runtime;

  @override
  Widget build(BuildContext context) {
    final seconds = (runtime['effective_elapsed_seconds'] as num?)?.toInt();
    final cached = runtime['_cached'] == true;
    final value = seconds == null ? '—' : formatElapsed(seconds);
    return Card(
        child: Padding(
            padding: const EdgeInsets.all(16),
            child: Wrap(
                spacing: 16,
                runSpacing: 8,
                crossAxisAlignment: WrapCrossAlignment.center,
                children: [
                  const Icon(Icons.timer_outlined, color: NexoTheme.amber),
                  Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Text(cached
                            ? 'آخرین زمان تأییدشده تیم'
                            : 'زمان سپری‌شده تیم'),
                        Text(value,
                            textDirection: TextDirection.ltr,
                            style: const TextStyle(
                                fontSize: 28,
                                fontWeight: FontWeight.w800,
                                color: NexoTheme.amber)),
                      ]),
                  Text(cached ? 'اتصال قطع شده' : 'زمان سرور',
                      style: const TextStyle(color: NexoTheme.muted)),
                ])));
  }

  static String formatElapsed(int seconds) {
    final safe = seconds < 0 ? 0 : seconds;
    return [safe ~/ 3600, (safe ~/ 60) % 60, safe % 60]
        .map((part) => part.toString().padLeft(2, '0'))
        .join(':');
  }
}
