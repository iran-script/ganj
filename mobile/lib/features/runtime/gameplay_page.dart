import 'dart:async';
import 'dart:convert';

import 'package:flutter/material.dart';
import 'package:uuid/uuid.dart';

import '../../app/nexo_theme.dart';
import '../../core/api/api_client.dart';
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
      this.api,
      super.key});
  final String sessionId;
  final String gameName;
  final String? teamName;
  final RuntimeRepository? repository;
  final OfflineSyncService? syncService;
  final ApiClient? api;

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
  bool reconnecting = false;
  bool foreground = true;
  int scoreRefreshRevision = 0;
  final Map<String, String> operationKeys = {};
  Timer? poll;

  @override
  void initState() {
    super.initState();
    repository = widget.repository ?? RuntimeRepository();
    sync = widget.syncService ?? OfflineSyncService();
    WidgetsBinding.instance.addObserver(this);
    refresh();
    _startPolling();
  }

  @override
  void dispose() {
    poll?.cancel();
    WidgetsBinding.instance.removeObserver(this);
    super.dispose();
  }

  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    foreground = state == AppLifecycleState.resumed;
    if (foreground) {
      _startPolling();
      reconnect();
    } else {
      poll?.cancel();
      poll = null;
    }
  }

  void _startPolling() {
    poll?.cancel();
    if (!foreground || !mounted) return;
    poll = Timer.periodic(const Duration(seconds: 5), (_) => reconnect());
  }

  Future<void> refresh({bool refreshScore = false}) async {
    if (refreshing || !mounted) return;
    refreshing = true;
    try {
      final next = await repository.projection(widget.sessionId);
      if (!mounted) return;
      setState(() {
        runtime = next;
        error = null;
        if (refreshScore) scoreRefreshRevision++;
      });
      if (next['_cached'] == true) {
        sync.status.setState(SyncState.offline);
      }
    } catch (failure) {
      if (mounted) {
        setState(() => error = _gameStatusFailure(failure));
      }
    } finally {
      refreshing = false;
    }
  }

  Future<void> reconnect() async {
    if (!mounted || !foreground || reconnecting || sending || refreshing) {
      return;
    }
    reconnecting = true;
    try {
      await refresh();
      if (runtime == null || runtime!['_cached'] == true) return;
      await sync.sync();
      await refresh(refreshScore: true);
    } catch (_) {
      if (mounted) {
        sync.status.setState(SyncState.syncError, message: 'REFRESH_REQUIRED');
      }
    } finally {
      reconnecting = false;
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
      final operationKey = '$id:${jsonEncode(evidence)}';
      final key = operationKeys.putIfAbsent(operationKey, const Uuid().v4);
      if (node['status'] == 'AVAILABLE') {
        final startKey =
            operationKeys.putIfAbsent('$id:start', const Uuid().v4);
        await repository.start(id, key: startKey);
        operationKeys.remove('$id:start');
      }
      final config = node['config'] as Map<String, dynamic>? ?? {};
      final result = await repository.submitWithOfflineFallback(
          sessionId: widget.sessionId,
          nodeId: id,
          evidence: evidence,
          nodeType: node['node_type'] as String,
          delayedValidationAllowed:
              config['offline_submission_allowed'] == true,
          teamId: runtime?['team_id'] as String?,
          idempotencyKey: key);
      operationKeys.remove(operationKey);
      if (!mounted) return;
      if (result['pending_verification'] == true) {
        sync.status.setState(SyncState.actionPending);
        return;
      }
      await refresh(refreshScore: true);
      if (result['accepted'] == false) {
        throw const ApiError(code: 'ANSWER_REJECTED', message: '');
      }
    } catch (failure) {
      final safe = failure is ApiError
          ? failure
          : const ApiError(code: 'NETWORK_UNAVAILABLE', message: '');
      if (mounted) setState(() => error = safe.userMessage);
      if (failure is! ApiError || !ApiClient.isNetworkFailure(failure)) {
        operationKeys.remove('${node['id']}:${jsonEncode(evidence)}');
      }
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
    final sessionStatus = data?['session_status'] as String?;
    final paused = sessionStatus == 'PAUSED' || data?['status'] == 'PAUSED';
    final sessionFinished = sessionStatus == 'FINISHED';
    final sessionCancelled = sessionStatus == 'CANCELLED';
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
                                    if (paused)
                                      const NexoNotice(
                                          'نشست بازی موقتاً متوقف شده است. پس از ادامه از سوی مدیر بازی، وضعیت خودکار به‌روز می‌شود.'),
                                    if (sessionFinished)
                                      const NexoNotice(
                                          'نشست بازی به پایان رسیده است. نتیجه نهایی از سرور دریافت می‌شود.',
                                          icon: Icons.flag_outlined,
                                          color: NexoTheme.success),
                                    if (sessionCancelled)
                                      const NexoNotice(
                                          'این نشست بازی لغو شده است.',
                                          icon: Icons.info_outline),
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
                                    ] else if (sessionFinished ||
                                        sessionCancelled) ...[
                                      const SizedBox(height: 16),
                                      Text(
                                          sessionFinished
                                              ? 'بازی پایان یافت'
                                              : 'بازی لغو شد',
                                          textAlign: TextAlign.center,
                                          style: Theme.of(context)
                                              .textTheme
                                              .headlineSmall),
                                    ] else if (node != null) ...[
                                      for (final message
                                          in data['private_messages']
                                                  as List? ??
                                              [])
                                        Card(
                                            child: Padding(
                                                padding:
                                                    const EdgeInsets.all(16),
                                                child: Text(
                                                    (message as Map)['text']
                                                            as String? ??
                                                        ''))),
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
                                            onPressed: paused
                                                ? null
                                                : () => setState(
                                                    () => brief = false),
                                            icon: const Icon(Icons.play_arrow),
                                            label:
                                                const Text('ورود به مأموریت')),
                                        const SizedBox(height: 24),
                                      ] else ...[
                                        AbsorbPointer(
                                            absorbing: sending ||
                                                paused ||
                                                sessionStatus == 'FINISHED' ||
                                                sessionStatus == 'CANCELLED',
                                            child: NodeRenderer(
                                                key: ValueKey(node['id']),
                                                node: node,
                                                sessionId: widget.sessionId,
                                                onEvidenceChanged: refresh,
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
                                            nodeId: node['id'] as String,
                                            enabled: !paused,
                                            api: widget.api),
                                      ],
                                    ] else
                                      const NexoNotice(
                                          'در انتظار وضعیت بعدی تیم از سرور'),
                                    ScorePanel(
                                        sessionId: widget.sessionId,
                                        teamId:
                                            data['team_id'] as String? ?? '',
                                        api: widget.api,
                                        refreshRevision: scoreRefreshRevision),
                                  ])))))),
    );
  }
}

String _gameStatusFailure(Object failure) {
  if (failure is ApiError) {
    final statusCode = failure.statusCode;
    if (statusCode != null) {
      return switch (statusCode) {
        401 => 'نشست ورود شما پایان یافته است. دوباره وارد حساب شوید.',
        403 => 'این دستگاه اجازه مشاهده وضعیت این بازی را ندارد.',
        404 => 'نشست بازی پیدا نشد یا دیگر در دسترس نیست.',
        422 => 'وضعیت نشست برای این بازیکن معتبر نیست. صفحه را تازه کنید.',
        >= 500 => 'سرور موقتاً پاسخ‌گو نیست. کمی بعد دوباره تلاش کنید.',
        _ when failure.code == 'INVALID_RESPONSE' =>
          'پاسخ وضعیت بازی از سرور معتبر نبود. دوباره تلاش کنید.',
        _ => 'دریافت وضعیت بازی انجام نشد. دوباره تلاش کنید.',
      };
    }
    if (failure.code == 'INVALID_RESPONSE') {
      return 'پاسخ وضعیت بازی از سرور معتبر نبود. دوباره تلاش کنید.';
    }
  }
  return switch (ApiClient.diagnosticFailureKind(failure)) {
    'timeout' => 'پاسخ سرور دیر رسید. اتصال را بررسی و دوباره تلاش کنید.',
    'tls' =>
      'اتصال امن به سرور برقرار نشد. تاریخ و اینترنت گوشی را بررسی کنید.',
    'socket' => 'سرور در دسترس نیست. اینترنت گوشی را بررسی کنید.',
    _ => 'دریافت وضعیت بازی انجام نشد. اتصال را بررسی و دوباره تلاش کنید.',
  };
}
