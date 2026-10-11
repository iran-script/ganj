import 'dart:async';

import 'package:flutter/material.dart';

import '../../app/nexo_theme.dart';
import '../../core/errors/api_error.dart';
import '../../core/offline/offline_sync_service.dart';
import '../../core/offline/sync_state.dart';
import '../scoring/score_panel.dart';
import '../hints/hints_panel.dart';
import 'node_renderer.dart';
import 'runtime_repository.dart';
import 'server_timer.dart';

class GameplayPage extends StatefulWidget {
  const GameplayPage(
      {required this.sessionId,
      this.gameName = 'بازی فعال',
      this.teamName,
      this.repository,
      this.syncService,
      super.key});
  final String sessionId;
  final String gameName;
  final String? teamName;
  final RuntimeRepository? repository;
  final OfflineSyncService? syncService;

  @override
  State<GameplayPage> createState() => _GameplayPageState();
}

class _GameplayPageState extends State<GameplayPage>
    with WidgetsBindingObserver {
  late final RuntimeRepository repository;
  late final OfflineSyncService sync;
  Map<String, dynamic>? runtime;
  String? error;
  bool brief = true;
  bool sending = false;
  bool refreshing = false;
  Timer? poll;

  @override
  void initState() {
    super.initState();
    repository = widget.repository ?? RuntimeRepository();
    sync = widget.syncService ?? OfflineSyncService();
    WidgetsBinding.instance.addObserver(this);
    refresh();
    poll = Timer.periodic(const Duration(seconds: 5), (_) => reconnect());
  }

  @override
  void dispose() {
    poll?.cancel();
    WidgetsBinding.instance.removeObserver(this);
    super.dispose();
  }

  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    if (state == AppLifecycleState.resumed) reconnect();
  }

  Future<void> refresh() async {
    if (refreshing || !mounted) return;
    refreshing = true;
    try {
      final next = await repository.projection(widget.sessionId);
      if (!mounted) return;
      setState(() {
        runtime = next;
      });
      if (next['_cached'] == true) {
        sync.status.setState(SyncState.offline);
      }
    } catch (_) {
      if (mounted) {
        setState(
            () => error = 'دریافت وضعیت بازی ممکن نیست. اتصال را بررسی کنید.');
      }
    } finally {
      refreshing = false;
    }
  }

  Future<void> reconnect() async {
    if (!mounted ||
        sending ||
        refreshing ||
        WidgetsBinding.instance.lifecycleState == AppLifecycleState.paused) {
      return;
    }
    try {
      await refresh();
      if (runtime == null || runtime!['_cached'] == true) return;
      await sync.sync();
      await refresh();
    } catch (_) {
      if (mounted) {
        sync.status.setState(SyncState.syncError, message: 'REFRESH_REQUIRED');
      }
    }
  }

  Future<void> submit(
      Map<String, dynamic> evidence, Map<String, dynamic> node) async {
    if (sending) return;
    setState(() {
      sending = true;
      error = null;
    });
    try {
      final id = node['id'] as String;
      if (node['status'] == 'AVAILABLE') await repository.start(id);
      final config = node['config'] as Map<String, dynamic>? ?? {};
      final result = await repository.submitWithOfflineFallback(
          sessionId: widget.sessionId,
          nodeId: id,
          evidence: evidence,
          nodeType: node['node_type'] as String,
          delayedValidationAllowed:
              config['offline_submission_allowed'] == true,
          teamId: runtime?['team_id'] as String?);
      if (!mounted) return;
      if (result['pending_verification'] == true) {
        sync.status.setState(SyncState.actionPending);
        return;
      }
      await refresh();
      if (result['accepted'] == false) {
        throw const ApiError(code: 'ANSWER_REJECTED', message: '');
      }
    } catch (failure) {
      final safe = failure is ApiError
          ? failure
          : const ApiError(code: 'NETWORK_UNAVAILABLE', message: '');
      if (mounted) setState(() => error = safe.userMessage);
      throw safe;
    } finally {
      if (mounted) setState(() => sending = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final data = runtime;
    final nodes =
        (data?['nodes'] as List?)?.whereType<Map<String, dynamic>>().toList() ??
            [];
    final active = (data?['active_node_ids'] as List?) ?? [];
    final node = nodes.where((node) => active.contains(node['id'])).firstOrNull;
    final complete = data?['status'] == 'COMPLETED';
    return Scaffold(
      appBar: AppBar(title: const Text('NEXO'), actions: [
        IconButton(
            tooltip: 'تازه‌سازی وضعیت سرور',
            onPressed: reconnect,
            icon: const Icon(Icons.refresh)),
      ]),
      body: SafeArea(
          child: Center(
              child: ConstrainedBox(
                  constraints: const BoxConstraints(maxWidth: 720),
                  child: data == null
                      ? Center(
                          child: error == null
                              ? const CircularProgressIndicator()
                              : Column(
                                  mainAxisSize: MainAxisSize.min,
                                  children: [
                                      Padding(
                                          padding: const EdgeInsets.all(24),
                                          child: NexoNotice(error!)),
                                      FilledButton(
                                          onPressed: refresh,
                                          child: const Text('تلاش دوباره')),
                                    ]))
                      : AnimatedBuilder(
                          animation: sync.status,
                          builder: (context, _) => RefreshIndicator(
                              onRefresh: reconnect,
                              child: ListView(
                                  padding: const EdgeInsets.all(24),
                                  physics:
                                      const AlwaysScrollableScrollPhysics(),
                                  children: [
                                    if (sync.status.userMessage != null)
                                      NexoNotice(sync.status.userMessage!),
                                    if (error != null)
                                      NexoNotice(error!,
                                          icon: Icons.error_outline,
                                          color: NexoTheme.danger),
                                    Text(widget.gameName,
                                        style: Theme.of(context)
                                            .textTheme
                                            .headlineSmall
                                            ?.copyWith(
                                                fontWeight: FontWeight.w800)),
                                    const SizedBox(height: 8),
                                    Text(widget.teamName ?? 'همراه تیم شما',
                                        style: const TextStyle(
                                            color: NexoTheme.muted)),
                                    const SizedBox(height: 24),
                                    ServerTimer(runtime: data),
                                    if (complete) ...[
                                      const Icon(Icons.check_circle_outline,
                                          size: 64, color: NexoTheme.success),
                                      const SizedBox(height: 16),
                                      Text('مأموریت کامل شد',
                                          textAlign: TextAlign.center,
                                          style: Theme.of(context)
                                              .textTheme
                                              .headlineMedium),
                                      const SizedBox(height: 8),
                                      const Text(
                                          'تیم شما به پایان مسیر رسید. نتیجه از سرور دریافت می‌شود.',
                                          textAlign: TextAlign.center),
                                      const SizedBox(height: 24),
                                    ] else if (node != null) ...[
                                      Text(brief ? 'شرح مأموریت' : 'هدف فعلی',
                                          style: const TextStyle(
                                              color: NexoTheme.amber)),
                                      const SizedBox(height: 12),
                                      Text(
                                          node['title'] as String? ??
                                              'مرحله جاری',
                                          style: Theme.of(context)
                                              .textTheme
                                              .headlineMedium
                                              ?.copyWith(
                                                  fontWeight: FontWeight.w800)),
                                      const SizedBox(height: 24),
                                      if (brief) ...[
                                        Card(
                                            child: Padding(
                                                padding:
                                                    const EdgeInsets.all(24),
                                                child: Text(
                                                    (node['config'] as Map?)?[
                                                                'prompt']
                                                            as String? ??
                                                        (node['config']
                                                                    as Map?)?[
                                                                'text']
                                                            as String? ??
                                                        'با تیم خود همراه بمانید و دستور مرحله جاری را دنبال کنید.',
                                                    style: const TextStyle(
                                                        fontSize: 18,
                                                        height: 1.8)))),
                                        FilledButton.icon(
                                            onPressed: () =>
                                                setState(() => brief = false),
                                            icon: const Icon(Icons.play_arrow),
                                            label:
                                                const Text('ورود به مأموریت')),
                                        const SizedBox(height: 24),
                                      ] else ...[
                                        AbsorbPointer(
                                            absorbing: sending,
                                            child: NodeRenderer(
                                                key: ValueKey(node['id']),
                                                node: node,
                                                sessionId: widget.sessionId,
                                                onSubmit: (evidence) =>
                                                    submit(evidence, node))),
                                        if (sending)
                                          const Padding(
                                              padding: EdgeInsets.all(16),
                                              child: Center(
                                                  child:
                                                      CircularProgressIndicator())),
                                        HintsPanel(
                                            key:
                                                ValueKey('hints-${node['id']}'),
                                            nodeId: node['id'] as String),
                                      ],
                                    ] else
                                      const NexoNotice(
                                          'در انتظار وضعیت بعدی تیم از سرور'),
                                    ScorePanel(
                                        key: ValueKey(
                                            '${data['effective_elapsed_seconds']}-${data['status']}'),
                                        sessionId: widget.sessionId,
                                        teamId:
                                            data['team_id'] as String? ?? ''),
                                  ])))))),
    );
  }
}
