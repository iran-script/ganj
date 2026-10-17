import 'package:flutter/material.dart';

import '../../app/nexo_components.dart';
import '../../core/api/api_client.dart';

class LeaderboardPage extends StatefulWidget {
  const LeaderboardPage({required this.sessionId, this.api, super.key});

  final String sessionId;
  final ApiClient? api;

  @override
  State<LeaderboardPage> createState() => _LeaderboardPageState();
}

class _LeaderboardPageState extends State<LeaderboardPage> {
  late final ApiClient api = widget.api ?? ApiClient();
  late Future<Map<String, dynamic>> future;

  @override
  void initState() {
    super.initState();
    future = _load();
  }

  Future<Map<String, dynamic>> _load() =>
      api.get('/game-sessions/${widget.sessionId}/leaderboard');

  void _retry() => setState(() => future = _load());

  @override
  Widget build(BuildContext context) => Scaffold(
      appBar: AppBar(title: const Text('رتبه‌بندی')),
      body: FutureBuilder<Map<String, dynamic>>(
          future: future,
          builder: (context, snapshot) {
            if (snapshot.connectionState != ConnectionState.done) {
              return const NexoLoading(message: 'در حال دریافت رتبه‌بندی…');
            }
            if (snapshot.hasError) {
              return NexoErrorView(
                  message: ApiClient.isNetworkFailure(snapshot.error!)
                      ? 'رتبه‌بندی دریافت نشد. اتصال را بررسی کنید.'
                      : 'رتبه‌بندی فعلاً در دسترس نیست.',
                  offline: ApiClient.isNetworkFailure(snapshot.error!),
                  onRetry: _retry);
            }
            final rows = (snapshot.data?['rankings'] as List<dynamic>?) ?? [];
            if (rows.isEmpty) {
              return const Center(
                  child: Padding(
                      padding: EdgeInsets.all(24),
                      child: NexoEmptyView(
                          title: 'هنوز رتبه‌ای ثبت نشده',
                          message:
                              'با ثبت نتیجه مأموریت‌ها، رتبه‌بندی به‌روز می‌شود.',
                          icon: Icons.emoji_events_outlined)));
            }
            return ListView.builder(
                padding: const EdgeInsets.symmetric(vertical: 8),
                itemCount: rows.length,
                itemBuilder: (context, index) {
                  final row = rows[index] as Map<String, dynamic>;
                  return Card(
                      margin: const EdgeInsets.symmetric(
                          horizontal: 16, vertical: 5),
                      child: ListTile(
                          leading: CircleAvatar(child: Text('${row['rank']}')),
                          title: Text(row['own_team'] == true
                              ? 'تیم شما'
                              : 'تیم ${row['rank']}'),
                          subtitle: Text('امتیاز: ${row['score'] ?? 'پنهان'}'),
                          trailing: Text(
                              '${row['effective_elapsed_seconds'] ?? 0} ثانیه')));
                });
          }));
}
