import 'package:flutter/material.dart';

import '../../app/nexo_components.dart';
import '../../app/nexo_theme.dart';
import '../../core/api/api_client.dart';
import '../leaderboard/leaderboard_page.dart';
import 'score_panel.dart';

class GameResultPage extends StatefulWidget {
  const GameResultPage({required this.sessionId, required this.teamId, required this.gameName, super.key});

  final String sessionId;
  final String teamId;
  final String gameName;

  @override
  State<GameResultPage> createState() => _GameResultPageState();
}

class _GameResultPageState extends State<GameResultPage> {
  late final Future<Map<String, dynamic>> sessionState =
      ApiClient().get('/game-sessions/${widget.sessionId}/state');

  @override
  Widget build(BuildContext context) => Scaffold(
        appBar: AppBar(title: const Text('نتیجه بازی')),
        body: SafeArea(
          child: ListView(
            padding: const EdgeInsets.all(NexoSpace.x4),
            children: [
              NexoCityHeader(
                eyebrow: 'نتیجه ثبت‌شده',
                title: widget.gameName,
                body: 'امتیاز و رتبه‌بندی از سرور بازی دریافت می‌شود.',
              ),
              ScorePanel(sessionId: widget.sessionId, teamId: widget.teamId),
              FilledButton.icon(
                onPressed: () => Navigator.of(context).push(MaterialPageRoute<void>(
                  builder: (_) => LeaderboardPage(sessionId: widget.sessionId),
                )),
                icon: const Icon(Icons.emoji_events_outlined),
                label: const Text('مشاهده رتبه‌بندی'),
              ),
              const SizedBox(height: 12),
              FutureBuilder<Map<String, dynamic>>(
                future: sessionState,
                builder: (context, snapshot) {
                  if (snapshot.hasError) {
                    return const NexoNotice('وضعیت پایان نشست فعلاً دریافت نشد؛ نتیجه امتیاز از API جداگانه بارگیری می‌شود.', icon: Icons.info_outline, color: NexoTheme.information);
                  }
                  final state = snapshot.data?['status'] as String?;
                  return Text(
                    state == null ? 'وضعیت نهایی نشست در حال بررسی است.' : 'وضعیت نشست: ${_status(state)}',
                    textAlign: TextAlign.center,
                    style: const TextStyle(color: NexoTheme.muted),
                  );
                },
              ),
            ],
          ),
        ),
      );

  String _status(String value) => switch (value) {
        'FINISHED' => 'پایان‌یافته',
        'CANCELLED' => 'لغوشده',
        _ => value,
      };
}
