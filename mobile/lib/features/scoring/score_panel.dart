import 'package:flutter/material.dart';

import '../../core/api/api_client.dart';
import '../leaderboard/leaderboard_page.dart';

class ScorePanel extends StatefulWidget {
  const ScorePanel({required this.sessionId, required this.teamId, super.key});

  final String sessionId;
  final String teamId;

  @override
  State<ScorePanel> createState() => _ScorePanelState();
}
class _ScorePanelState extends State<ScorePanel> {
  late Future<Map<String, dynamic>> score;

  @override
  void initState() {
    super.initState();
    score = ApiClient().get('/game-sessions/${widget.sessionId}/teams/${widget.teamId}/score');
  }

  @override
  Widget build(BuildContext context) => FutureBuilder<Map<String, dynamic>>(future: score, builder: (context, snapshot) {
        if (!snapshot.hasData) return const SizedBox.shrink();
        final value = snapshot.data!;
        return Card(child: ListTile(leading: const Icon(Icons.stars), title: Text('امتیاز تیم: ${value['score'] ?? 0}'), subtitle: Text('راهنما: ${value['hints_used'] ?? 0}  |  زمان مؤثر: ${value['effective_elapsed_seconds'] ?? 0} ثانیه'), trailing: IconButton(icon: const Icon(Icons.emoji_events), onPressed: () => Navigator.push(context, MaterialPageRoute(builder: (_) => LeaderboardPage(sessionId: widget.sessionId))))));
      });
}
