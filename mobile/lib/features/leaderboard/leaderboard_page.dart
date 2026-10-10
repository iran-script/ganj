import 'package:flutter/material.dart';

import '../../core/api/api_client.dart';

class LeaderboardPage extends StatefulWidget {
  const LeaderboardPage({required this.sessionId, super.key});

  final String sessionId;

  @override
  State<LeaderboardPage> createState() => _LeaderboardPageState();
}
class _LeaderboardPageState extends State<LeaderboardPage> {
  late Future<Map<String, dynamic>> future;

  @override
  void initState() {
    super.initState();
    future = ApiClient().get('/game-sessions/${widget.sessionId}/leaderboard');
  }

  @override
  Widget build(BuildContext context) => Scaffold(appBar: AppBar(title: const Text('رتبه‌بندی')), body: FutureBuilder<Map<String, dynamic>>(future: future, builder: (context, snapshot) {
        if (snapshot.connectionState != ConnectionState.done) return const Center(child: CircularProgressIndicator());
        if (snapshot.hasError) return Center(child: OutlinedButton(onPressed: () => setState(() => future = ApiClient().get('/game-sessions/${widget.sessionId}/leaderboard')), child: const Text('تلاش دوباره')));
        final rows = (snapshot.data?['rankings'] as List<dynamic>?) ?? [];
        if (rows.isEmpty) return const Center(child: Text('رتبه‌بندی هنوز آماده نیست.'));
        return ListView.builder(itemCount: rows.length, itemBuilder: (context, index) { final row = rows[index] as Map<String, dynamic>; return ListTile(leading: CircleAvatar(child: Text('${row['rank']}')), title: Text(row['own_team'] == true ? 'تیم شما' : 'تیم'), subtitle: Text('امتیاز: ${row['score'] ?? 'پنهان'}'), trailing: Text('${row['effective_elapsed_seconds'] ?? 0} ثانیه')); });
      }));
}
