import 'package:flutter/material.dart';

import '../../core/errors/api_error.dart';
import '../../core/offline/offline_sync_service.dart';
import '../../core/offline/sync_state.dart';
import '../scoring/score_panel.dart';
import '../hints/hints_panel.dart';
import 'node_renderer.dart';
import 'runtime_repository.dart';

class GameplayPage extends StatefulWidget {
  const GameplayPage({required this.sessionId, super.key});

  final String sessionId;

  @override
  State<GameplayPage> createState() => _GameplayPageState();
}

class _GameplayPageState extends State<GameplayPage>
    with WidgetsBindingObserver {
  final repository = RuntimeRepository();
  final sync = OfflineSyncService();
  late Future<Map<String, dynamic>> future;
  String? error;

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addObserver(this);
    refresh();
  }

  @override
  void dispose() {
    WidgetsBinding.instance.removeObserver(this);
    super.dispose();
  }

  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    if (state == AppLifecycleState.resumed) {
      reconnect();
    }
  }

  void refresh() {
    setState(() {
      error = null;
      future = repository.projection(widget.sessionId);
    });
  }

  Future<void> reconnect() async {
    try {
      refresh();
      await future;
      await sync.sync();
      refresh();
    } catch (_) {
      if (mounted) {
        setState(() => error = 'همگام‌سازی پس از اتصال دوباره انجام نشد.');
      }
    }
  }

  Future<void> submit(
      Map<String, dynamic> evidence, Map<String, dynamic> node) async {
    try {
      final id = node['id'] as String;
      final status = node['status'] as String?;
      if (status == 'AVAILABLE') await repository.start(id);
      final config = node['config'] as Map<String, dynamic>? ?? {};
      final result = await repository.submitWithOfflineFallback(
          sessionId: widget.sessionId,
          nodeId: id,
          evidence: evidence,
          nodeType: node['node_type'] as String,
          delayedValidationAllowed:
              config['offline_submission_allowed'] == true,
          teamId: null);
      if (result['pending_verification'] == true && mounted) {
        setState(() => error = 'عملیات پس از اتصال به سرور بررسی می‌شود.');
        return;
      }
      refresh();
    } on ApiError catch (exception) {
      if (mounted) setState(() => error = exception.userMessage);
    }
  }

  @override
  Widget build(BuildContext context) => Scaffold(
        appBar: AppBar(title: const Text('بازی'), actions: [
          IconButton(onPressed: refresh, icon: const Icon(Icons.refresh))
        ]),
        body: FutureBuilder<Map<String, dynamic>>(
          future: future,
          builder: (context, snapshot) {
            if (snapshot.connectionState != ConnectionState.done) {
              return const Center(child: CircularProgressIndicator());
            }
            if (snapshot.hasError) {
              return Center(
                  child: FilledButton(
                      onPressed: refresh, child: const Text('تلاش دوباره')));
            }
            final runtime = snapshot.data ?? {};
            final nodes = (runtime['nodes'] as List<dynamic>?) ?? [];
            final activeId = (runtime['active_node_ids'] as List<dynamic>?)
                ?.cast<String>()
                .firstOrNull;
            final node = nodes.cast<Map<String, dynamic>?>().firstWhere(
                (item) => item?['id'] == activeId,
                orElse: () => null);
            final player = runtime['player'] as Map<String, dynamic>?;
            return AnimatedBuilder(
                animation: sync.status,
                builder: (context, _) => RefreshIndicator(
                    onRefresh: () async {
                      await sync.sync();
                      refresh();
                      await future;
                    },
                    child:
                        ListView(padding: const EdgeInsets.all(16), children: [
                      _SyncBanner(status: sync.status),
                      if (error != null)
                        Padding(
                            padding: const EdgeInsets.only(bottom: 12),
                            child: Text(error!,
                                style: const TextStyle(color: Colors.red))),
                      _ContextCard(runtime: runtime, player: player),
                      if (node != null)
                        NodeRenderer(
                            node: node,
                            sessionId: widget.sessionId,
                            onSubmit: (evidence) => submit(evidence, node)),
                      if (node != null)
                        HintsPanel(nodeId: node['id'] as String),
                      if (node == null)
                        const Card(
                            child: Padding(
                                padding: EdgeInsets.all(16),
                                child: Text('مرحله فعالی وجود ندارد.'))),
                      ScorePanel(
                          sessionId: widget.sessionId,
                          teamId: runtime['team_id'] as String? ?? ''),
                    ])));
          },
        ),
      );
}

class _SyncBanner extends StatelessWidget {
  const _SyncBanner({required this.status});

  final SyncStatus status;

  @override
  Widget build(BuildContext context) {
    final text = switch (status.state) {
      SyncState.online => null,
      SyncState.reconnecting => 'در حال اتصال دوباره…',
      SyncState.offline => 'آفلاین؛ داده امن ذخیره شده است.',
      SyncState.syncing => 'در حال همگام‌سازی…',
      SyncState.actionPending =>
        '${status.pendingCount} عملیات در انتظار بررسی سرور است.',
      SyncState.syncError => status.error ?? 'همگام‌سازی با خطا روبه‌رو شد.',
    };
    if (text == null) return const SizedBox.shrink();
    return Card(
        color: Theme.of(context).colorScheme.secondaryContainer,
        child: Padding(padding: const EdgeInsets.all(12), child: Text(text)));
  }
}

class _ContextCard extends StatelessWidget {
  const _ContextCard({required this.runtime, required this.player});

  final Map<String, dynamic> runtime;
  final Map<String, dynamic>? player;

  @override
  Widget build(BuildContext context) {
    final roles = (runtime['roles'] as List<dynamic>?) ?? [];
    final role = roles.cast<Map<String, dynamic>>().firstWhere(
        (item) => item['player_id'] == player?['id'],
        orElse: () => {});
    return Card(
        child: ListTile(
            leading:
                Icon(player?['support'] == true ? Icons.groups : Icons.person),
            title: Text(player?['support'] == true
                ? 'Support'
                : role['role_code'] as String? ?? 'بازیکن'),
            subtitle: Text('وضعیت تیم: ${runtime['status'] ?? ''}')));
  }
}
