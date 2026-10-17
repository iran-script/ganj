import 'package:flutter/material.dart';

import '../../app/nexo_components.dart';
import '../../core/api/api_client.dart';
import '../leaderboard/leaderboard_page.dart';

class ScorePanel extends StatefulWidget {
  const ScorePanel({
    required this.sessionId,
    required this.teamId,
    this.api,
    this.refreshRevision = 0,
    super.key,
  });

  final String sessionId;
  final String teamId;
  final ApiClient? api;
  final int refreshRevision;

  @override
  State<ScorePanel> createState() => _ScorePanelState();
}

class _ScorePanelState extends State<ScorePanel> {
  late final ApiClient api = widget.api ?? ApiClient();
  late Future<Map<String, dynamic>> score;

  @override
  void initState() {
    super.initState();
    score = _load();
  }

  @override
  void didUpdateWidget(covariant ScorePanel oldWidget) {
    super.didUpdateWidget(oldWidget);
    if (oldWidget.sessionId != widget.sessionId ||
        oldWidget.teamId != widget.teamId ||
        oldWidget.refreshRevision != widget.refreshRevision) {
      score = _load();
    }
  }

  Future<Map<String, dynamic>> _load() => api
      .get('/game-sessions/${widget.sessionId}/teams/${widget.teamId}/score');

  void _retry() => setState(() => score = _load());

  @override
  Widget build(BuildContext context) => FutureBuilder<Map<String, dynamic>>(
      future: score,
      builder: (context, snapshot) {
        if (snapshot.connectionState != ConnectionState.done) {
          return const Card(
              child: Padding(
                  padding: EdgeInsets.all(20),
                  child: Row(children: [
                    SizedBox.square(
                        dimension: 22,
                        child: CircularProgressIndicator(strokeWidth: 2)),
                    SizedBox(width: 12),
                    Text('در حال دریافت امتیاز از سرور…'),
                  ])));
        }
        if (snapshot.hasError) {
          return NexoErrorView(
              message: ApiClient.isNetworkFailure(snapshot.error!)
                  ? 'دریافت امتیاز ممکن نشد. اتصال را بررسی و دوباره تلاش کنید.'
                  : 'امتیاز فعلاً در دسترس نیست. دوباره تلاش کنید.',
              offline: ApiClient.isNetworkFailure(snapshot.error!),
              onRetry: _retry);
        }
        final value = snapshot.data!;
        return Card(
            child: ListTile(
                leading: const Icon(Icons.stars),
                title: Text('امتیاز تیم: ${value['score'] ?? 0}'),
                subtitle: Text(
                    'راهنما: ${value['hints_used'] ?? 0}  |  زمان مؤثر: ${value['effective_elapsed_seconds'] ?? 0} ثانیه'),
                trailing: IconButton(
                    tooltip: 'رتبه‌بندی',
                    icon: const Icon(Icons.emoji_events),
                    onPressed: () => Navigator.push(
                        context,
                        MaterialPageRoute(
                            builder: (_) => LeaderboardPage(
                                sessionId: widget.sessionId,
                                api: widget.api))))));
      });
}
