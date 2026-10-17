import 'package:flutter/material.dart';

import '../../app/nexo_components.dart';
import '../../app/nexo_routes.dart';
import '../../app/nexo_theme.dart';
import '../../core/api/api_client.dart';
import '../../core/auth/auth_controller.dart';
import '../../core/errors/api_error.dart';
import '../../features/runtime/gameplay_page.dart';
import '../scoring/game_result_page.dart';

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

  Future<List<Map<String, dynamic>>> load() async => Future.wait([
        widget.auth.api.get('/games'),
        widget.auth.api.get('/game-sessions'),
      ]);

  Future<void> logout() async {
    if (await widget.auth.logout()) {
      return;
    }
    if (!mounted) {
      return;
    }
    final discard = await showDialog<bool>(
      context: context,
      builder: (context) => AlertDialog(
        title: const Text('اقدام‌های ذخیره‌شده'),
        content: const Text(
            'اقدام ارسال‌نشده دارید. پیش از خروج همگام‌سازی کنید یا حذف آن‌ها را تأیید کنید.'),
        actions: [
          TextButton(
              onPressed: () => Navigator.pop(context, false),
              child: const Text('بازگشت')),
          FilledButton(
              onPressed: () => Navigator.pop(context, true),
              child: const Text('حذف و خروج')),
        ],
      ),
    );
    if (discard == true) {
      await widget.auth.logout(discardPending: true);
    }
  }

  Future<void> refresh() async {
    setState(() => content = load());
    try {
      await content;
    } catch (_) {
      // The FutureBuilder presents the retry state.
    }
  }

  @override
  Widget build(BuildContext context) => Scaffold(
        appBar: AppBar(
          title: const NexoBrandMark(compact: true),
          actions: [
            IconButton(
              tooltip: 'راهنمای بازی',
              onPressed: () => Navigator.of(context).pushNamed(NexoRoutes.tutorial),
              icon: const Icon(Icons.help_outline),
            ),
            IconButton(
              tooltip: 'اعلان‌ها',
              onPressed: () => Navigator.of(context).pushNamed(NexoRoutes.notifications),
              icon: const Icon(Icons.notifications_none),
            ),
            IconButton(
              tooltip: 'پروفایل من',
              onPressed: () =>
                  Navigator.of(context).pushNamed(NexoRoutes.profile),
              icon: const Icon(Icons.account_circle_outlined),
            ),
            IconButton(
              tooltip: 'خروج از حساب',
              onPressed: logout,
              icon: const Icon(Icons.logout),
            ),
          ],
        ),
        body: SafeArea(
          top: false,
          child: Center(
            child: ConstrainedBox(
              constraints: const BoxConstraints(maxWidth: 720),
              child: FutureBuilder<List<Map<String, dynamic>>>(
                future: content,
                builder: (context, snapshot) {
                  if (snapshot.connectionState != ConnectionState.done) {
                    return const NexoLoading(message: 'دریافت بازی‌ها…');
                  }
                  if (snapshot.hasError) {
                    final offline = ApiClient.isNetworkFailure(
                        snapshot.error ?? StateError('Request failed'));
                    return NexoErrorView(
                      message: offline
                          ? 'برای دریافت بازی‌ها به اینترنت وصل شوید.'
                          : snapshot.error is ApiError
                              ? (snapshot.error! as ApiError).userMessage
                              : 'دریافت بازی‌ها انجام نشد.',
                      offline: offline,
                      onRetry: () => setState(() => content = load()),
                    );
                  }
                  final games = (snapshot.data![0]['items'] as List? ?? [])
                      .whereType<Map<String, dynamic>>()
                      .toList();
                  final sessions = (snapshot.data![1]['items'] as List? ?? [])
                      .whereType<Map<String, dynamic>>()
                      .toList();
                  final active = sessions
                      .where((session) =>
                          ['RUNNING', 'PAUSED'].contains(session['status']) &&
                          ownTeam(session, widget.auth.user?['id']) != null)
                      .toList();
                  final completed = sessions
                      .where((session) =>
                          session['status'] == 'FINISHED' &&
                          ownTeam(session, widget.auth.user?['id']) != null)
                      .toList();
                  final userName = widget.auth.profile?.name ??
                      widget.auth.user?['name'] as String? ??
                      'بازیکن';
                  return RefreshIndicator(
                    onRefresh: refresh,
                    child: ListView(
                      padding: const EdgeInsets.fromLTRB(
                          NexoSpace.x4, NexoSpace.x3, NexoSpace.x4, 40),
                      children: [
                        NexoCityHeader(
                          eyebrow: 'NEXO • بازی شهری',
                          title: 'سلام $userName',
                          body: active.isEmpty
                              ? 'آماده ماجراجویی بعدی هستید؟ نشست‌های قابل ثبت‌نام را ببینید.'
                              : 'وضعیت بازی فعال شما از سرور دریافت شده است.',
                        ),
                        Text('بازی‌های در دسترس', style: Theme.of(context).textTheme.titleLarge),
                        const SizedBox(height: NexoSpace.x2),
                        if (active.isEmpty && games.isEmpty)
                          const NexoEmptyView(
                            title: 'هنوز بازی‌ای در دسترس نیست',
                            message: 'وقتی برگزارکننده بازی‌ای برای بازیکنان منتشر کند، اینجا نمایش داده می‌شود.',
                          ),
                        if (active.isEmpty && games.isNotEmpty)
                          Card(
                            child: Padding(
                              padding: const EdgeInsets.all(NexoSpace.x4),
                              child: Column(
                                crossAxisAlignment: CrossAxisAlignment.stretch,
                                children: [
                                  const Text('هنوز بازی فعالی ندارید', style: TextStyle(fontWeight: FontWeight.w700, fontSize: 18)),
                                  const SizedBox(height: NexoSpace.x2),
                                  const Text('برای دیدن نشست‌های منتشرشده به بخش بازی‌ها بروید.', style: TextStyle(color: NexoTheme.muted)),
                                  const SizedBox(height: NexoSpace.x3),
                                  FilledButton.icon(
                                    onPressed: () => Navigator.of(context).pushNamed(NexoRoutes.games),
                                    icon: const Icon(Icons.explore_outlined),
                                    label: const Text('دیدن بازی‌ها'),
                                  ),
                                ],
                              ),
                            ),
                          ),
                        const SizedBox(height: NexoSpace.x3),
                        for (final session in active)
                          _ActiveSession(
                            session: session,
                            game: games
                                .where(
                                    (game) => game['id'] == session['game_id'])
                                .firstOrNull,
                            userId: widget.auth.user?['id'] as String?,
                          ),
                        if (completed.isNotEmpty) ...[
                          const SizedBox(height: NexoSpace.x4),
                          Text('نتیجه بازی‌های من', style: Theme.of(context).textTheme.titleLarge),
                          const SizedBox(height: NexoSpace.x2),
                          for (final session in completed)
                            _CompletedSession(
                              session: session,
                              game: games.where((game) => game['id'] == session['game_id']).firstOrNull,
                              userId: widget.auth.user?['id'] as String?,
                            ),
                        ],
                        if (active.isNotEmpty && games.isEmpty)
                          const NexoNotice('فهرست بازی‌ها فعلاً از سرور دریافت نشده است؛ وضعیت نشست فعال شما همچنان نمایش داده می‌شود.', icon: Icons.info_outline),
                      ],
                    ),
                  );
                },
              ),
            ),
          ),
        ),
        bottomNavigationBar: const NexoBottomNavigation(selectedIndex: 0),
      );
}

Map<String, dynamic>? ownTeam(Map<String, dynamic> session, dynamic userId) =>
    (session['teams'] as List? ?? [])
        .whereType<Map<String, dynamic>>()
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
    final running = session['status'] == 'RUNNING';
    return Card(
      child: Padding(
        padding: const EdgeInsets.all(NexoSpace.x4),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            Row(children: [
              Icon(running ? Icons.play_circle : Icons.pause_circle,
                  color: running ? NexoTheme.success : NexoTheme.amber),
              const SizedBox(width: NexoSpace.x2),
              Text(running ? 'بازی در جریان است' : 'بازی موقتاً متوقف است'),
            ]),
            const SizedBox(height: NexoSpace.x3),
            Text(game?['name'] as String? ?? 'بازی فعال',
                style: Theme.of(context).textTheme.titleLarge),
            const SizedBox(height: NexoSpace.x1),
            Text(team?['name'] as String? ?? 'تیم شما',
                style: const TextStyle(color: NexoTheme.muted)),
            const SizedBox(height: NexoSpace.x4),
            FilledButton.icon(
              icon: const Icon(Icons.play_arrow),
              label: const Text('ادامه بازی'),
              onPressed: () => Navigator.push(
                context,
                MaterialPageRoute(
                  builder: (_) => GameplayPage(
                    sessionId: session['id'] as String,
                    gameName: game?['name'] as String? ?? 'بازی فعال',
                    teamName: team?['name'] as String?,
                  ),
                ),
              ),
            ),
          ],
        ),
      ),
    );
  }
}

class _CompletedSession extends StatelessWidget {
  const _CompletedSession({required this.session, required this.game, required this.userId});

  final Map<String, dynamic> session;
  final Map<String, dynamic>? game;
  final String? userId;

  @override
  Widget build(BuildContext context) {
    final team = ownTeam(session, userId);
    if (team == null || team['team_id'] is! String) return const SizedBox.shrink();
    return Card(
      child: ListTile(
        leading: const Icon(Icons.emoji_events_outlined, color: NexoTheme.amber),
        title: Text(game?['name'] as String? ?? 'بازی پایان‌یافته'),
        subtitle: Text(team['name'] as String? ?? 'تیم شما'),
        trailing: const Icon(Icons.chevron_left),
        onTap: () => Navigator.of(context).push(MaterialPageRoute<void>(
          builder: (_) => GameResultPage(
            sessionId: session['id'] as String,
            teamId: team['team_id'] as String,
            gameName: game?['name'] as String? ?? 'بازی NEXO',
          ),
        )),
      ),
    );
  }
}
