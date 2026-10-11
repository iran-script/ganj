import 'package:flutter/material.dart';

import '../../app/nexo_theme.dart';

import '../../core/auth/auth_controller.dart';
import '../runtime/gameplay_page.dart';

class HomePage extends StatefulWidget {
  const HomePage({required this.auth, super.key});
  final AuthController auth;
  @override
  State<HomePage> createState() => _HomePageState();
}

class _HomePageState extends State<HomePage> {
  late Future<List<Map<String, dynamic>>> content;
  @override
  void initState() {
    super.initState();
    content = load();
  }

  Future<List<Map<String, dynamic>>> load() async => Future.wait(
      [widget.auth.api.get('/games'), widget.auth.api.get('/game-sessions')]);

  Future<void> logout() async {
    if (await widget.auth.logout()) return;
    if (!mounted) return;
    final discard = await showDialog<bool>(
        context: context,
        builder: (context) => AlertDialog(
                title: const Text('اقدام‌های در انتظار'),
                content: const Text(
                    'پیش از خروج، اقدام‌ها را همگام‌سازی کنید یا حذف آن‌ها را تأیید کنید.'),
                actions: [
                  TextButton(
                      onPressed: () => Navigator.pop(context, false),
                      child: const Text('انصراف')),
                  FilledButton(
                      onPressed: () => Navigator.pop(context, true),
                      child: const Text('حذف و خروج')),
                ]));
    if (discard == true) await widget.auth.logout(discardPending: true);
  }

  @override
  Widget build(BuildContext context) => Scaffold(
      appBar: AppBar(title: const Text('NEXO'), actions: [
        IconButton(
            tooltip: 'خروج از حساب',
            onPressed: logout,
            icon: const Icon(Icons.logout)),
      ]),
      body: SafeArea(
          child: Center(
              child: ConstrainedBox(
                  constraints: const BoxConstraints(maxWidth: 720),
                  child: FutureBuilder<List<Map<String, dynamic>>>(
                      future: content,
                      builder: (context, snapshot) {
                        if (snapshot.connectionState != ConnectionState.done) {
                          return const Center(
                              child: CircularProgressIndicator());
                        }
                        if (snapshot.hasError) {
                          return Center(
                              child: FilledButton(
                                  onPressed: () =>
                                      setState(() => content = load()),
                                  child: const Text(
                                      'اتصال برقرار نشد؛ تلاش دوباره')));
                        }
                        final games =
                            (snapshot.data![0]['items'] as List? ?? [])
                                .cast<Map<String, dynamic>>();
                        final sessions =
                            (snapshot.data![1]['items'] as List? ?? [])
                                .cast<Map<String, dynamic>>();
                        final active = sessions
                            .where((session) =>
                                ['RUNNING', 'PAUSED']
                                    .contains(session['status']) &&
                                ownTeam(session, widget.auth.user?['id']) !=
                                    null)
                            .toList();
                        return RefreshIndicator(
                            onRefresh: () async {
                              setState(() => content = load());
                              await content;
                            },
                            child: ListView(
                                padding: const EdgeInsets.all(24),
                                children: [
                                  Text(
                                      'سلام ${widget.auth.user?['name'] ?? ''}',
                                      style: Theme.of(context)
                                          .textTheme
                                          .headlineSmall
                                          ?.copyWith(
                                              fontWeight: FontWeight.w800)),
                                  const SizedBox(height: 8),
                                  const Text('شهر، میدان ماجراجویی شماست.',
                                      style: TextStyle(color: NexoTheme.muted)),
                                  const SizedBox(height: 24),
                                  for (final session in active)
                                    _ActiveSession(
                                        session: session,
                                        game: games
                                            .where((game) =>
                                                game['id'] ==
                                                session['game_id'])
                                            .firstOrNull,
                                        userId:
                                            widget.auth.user?['id'] as String?),
                                  Text('بازی‌های نکسو',
                                      style: Theme.of(context)
                                          .textTheme
                                          .headlineMedium
                                          ?.copyWith(
                                              fontWeight: FontWeight.w800)),
                                  const SizedBox(height: 8),
                                  const Text(
                                      'یک بازی انتخاب کنید و داستان آن را کشف کنید.'),
                                  const SizedBox(height: 24),
                                  if (games.isEmpty)
                                    const NexoNotice(
                                        'در حال حاضر بازی در دسترس نیست.'),
                                  for (final game in games)
                                    Card(
                                        child: Padding(
                                            padding: const EdgeInsets.all(24),
                                            child: Column(
                                                crossAxisAlignment:
                                                    CrossAxisAlignment.stretch,
                                                children: [
                                                  const Align(
                                                      alignment:
                                                          AlignmentDirectional
                                                              .centerStart,
                                                      child: Icon(
                                                          Icons
                                                              .explore_outlined,
                                                          color:
                                                              NexoTheme.amber,
                                                          size: 32)),
                                                  const SizedBox(height: 16),
                                                  Text(
                                                      game['name'] as String? ??
                                                          'بازی',
                                                      style: Theme.of(context)
                                                          .textTheme
                                                          .titleLarge
                                                          ?.copyWith(
                                                              fontWeight:
                                                                  FontWeight
                                                                      .w800)),
                                                  const SizedBox(height: 12),
                                                  Text(
                                                      game['description']
                                                              as String? ??
                                                          '',
                                                      style: const TextStyle(
                                                          height: 1.7)),
                                                  const SizedBox(height: 24),
                                                  FilledButton(
                                                      onPressed: () => Navigator.push(
                                                          context,
                                                          MaterialPageRoute(
                                                              builder: (_) => _GameDetail(
                                                                  game: game,
                                                                  sessions: sessions
                                                                      .where((s) =>
                                                                          s['game_id'] ==
                                                                          game[
                                                                              'id'])
                                                                      .toList(),
                                                                  userId: widget
                                                                          .auth
                                                                          .user?['id']
                                                                      as String?))),
                                                      child: const Text(
                                                          'مشاهده جزئیات')),
                                                ]))),
                                ]));
                      })))));
}

Map<String, dynamic>? ownTeam(Map<String, dynamic> session, dynamic userId) =>
    (session['teams'] as List? ?? [])
        .cast<Map<String, dynamic>>()
        .where((team) => (team['players'] as List? ?? []).whereType<Map>().any(
            (player) =>
                (player['user'] as Map?)?['id'] == userId &&
                !['REMOVED', 'DISQUALIFIED'].contains(player['status'])))
        .firstOrNull;

class _ActiveSession extends StatelessWidget {
  const _ActiveSession(
      {required this.session, required this.game, required this.userId});
  final Map<String, dynamic> session;
  final Map<String, dynamic>? game;
  final String? userId;
  @override
  Widget build(BuildContext context) {
    final team = ownTeam(session, userId);
    return Card(
        child: Padding(
            padding: const EdgeInsets.all(24),
            child: Column(
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: [
                  Text(
                      session['status'] == 'RUNNING'
                          ? '● جلسه فعال'
                          : 'جلسه متوقف است',
                      style: const TextStyle(color: NexoTheme.success)),
                  const SizedBox(height: 16),
                  Text(game?['name'] as String? ?? 'بازی فعال',
                      style: Theme.of(context)
                          .textTheme
                          .headlineSmall
                          ?.copyWith(fontWeight: FontWeight.w800)),
                  const SizedBox(height: 8),
                  Text(team?['name'] as String? ?? 'تیم شما'),
                  const SizedBox(height: 24),
                  FilledButton.icon(
                      icon: const Icon(Icons.play_arrow),
                      label: const Text('ادامه بازی'),
                      onPressed: () => Navigator.push(
                          context,
                          MaterialPageRoute(
                              builder: (_) => GameplayPage(
                                  sessionId: session['id'] as String,
                                  gameName:
                                      game?['name'] as String? ?? 'بازی فعال',
                                  teamName: team?['name'] as String?)))),
                ])));
  }
}

class _GameDetail extends StatelessWidget {
  const _GameDetail(
      {required this.game, required this.sessions, required this.userId});
  final Map<String, dynamic> game;
  final List<Map<String, dynamic>> sessions;
  final String? userId;
  @override
  Widget build(BuildContext context) => Scaffold(
      appBar: AppBar(title: const Text('NEXO')),
      body: SafeArea(
          child: Center(
              child: ConstrainedBox(
                  constraints: const BoxConstraints(maxWidth: 720),
                  child: ListView(padding: const EdgeInsets.all(24), children: [
                    const Icon(Icons.explore_outlined,
                        size: 64, color: NexoTheme.amber),
                    const SizedBox(height: 24),
                    Text(game['name'] as String? ?? 'بازی',
                        style: Theme.of(context)
                            .textTheme
                            .headlineLarge
                            ?.copyWith(fontWeight: FontWeight.w800)),
                    const SizedBox(height: 24),
                    Card(
                        child: Padding(
                            padding: const EdgeInsets.all(24),
                            child: Column(
                                crossAxisAlignment: CrossAxisAlignment.start,
                                children: [
                                  Text('داستان بازی',
                                      style: Theme.of(context)
                                          .textTheme
                                          .titleLarge),
                                  const SizedBox(height: 16),
                                  Text(game['description'] as String? ?? '',
                                      style: const TextStyle(height: 1.8)),
                                ]))),
                    for (final session in sessions)
                      if (['RUNNING', 'PAUSED'].contains(session['status']) &&
                          ownTeam(session, userId) != null)
                        _ActiveSession(
                            session: session, game: game, userId: userId),
                    if (!sessions.any((s) =>
                        ['RUNNING', 'PAUSED'].contains(s['status']) &&
                        ownTeam(s, userId) != null))
                      const NexoNotice(
                          'جلسه فعالی برای تیم شما وجود ندارد. وضعیت ثبت‌نام را با برگزارکننده بررسی کنید.'),
                  ])))));
}
