import 'package:flutter/material.dart';

import '../../core/api/api_client.dart';
import '../../core/errors/api_error.dart';
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

class _GameplayPageState extends State<GameplayPage> with WidgetsBindingObserver {
  final repository = RuntimeRepository();
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
    if (state == AppLifecycleState.resumed) refresh();
  }

  void refresh() {
    setState(() {
      error = null;
      future = repository.projection(widget.sessionId);
    });
  }

  Future<void> submit(Map<String, dynamic> evidence, Map<String, dynamic> node) async {
    try {
      final id = node['id'] as String;
      final status = node['status'] as String?;
      if (status == 'AVAILABLE') await repository.start(id);
      await repository.submit(id, evidence);
      refresh();
    } on ApiError catch (exception) {
      if (mounted) setState(() => error = exception.userMessage);
    }
  }

  @override
  Widget build(BuildContext context) => Scaffold(
        appBar: AppBar(title: const Text('بازی'), actions: [IconButton(onPressed: refresh, icon: const Icon(Icons.refresh))]),
        body: FutureBuilder<Map<String, dynamic>>(
          future: future,
          builder: (context, snapshot) {
            if (snapshot.connectionState != ConnectionState.done) return const Center(child: CircularProgressIndicator());
            if (snapshot.hasError) return Center(child: FilledButton(onPressed: refresh, child: const Text('تلاش دوباره')));
            final runtime = snapshot.data ?? {};
            final nodes = (runtime['nodes'] as List<dynamic>?) ?? [];
            final activeId = (runtime['active_node_ids'] as List<dynamic>?)?.cast<String>().firstOrNull;
            final node = nodes.cast<Map<String, dynamic>?>().firstWhere((item) => item?['id'] == activeId, orElse: () => null);
            final player = runtime['player'] as Map<String, dynamic>?;
            return RefreshIndicator(onRefresh: () async => refresh(), child: ListView(padding: const EdgeInsets.all(16), children: [
              if (error != null) Padding(padding: const EdgeInsets.only(bottom: 12), child: Text(error!, style: const TextStyle(color: Colors.red))),
              _ContextCard(runtime: runtime, player: player),
              if (node != null) NodeRenderer(node: node, sessionId: widget.sessionId, onSubmit: (evidence) => submit(evidence, node)),
              if (node != null) HintsPanel(nodeId: node['id'] as String),
              if (node == null) const Card(child: Padding(padding: EdgeInsets.all(16), child: Text('مرحله فعالی وجود ندارد.'))),
              ScorePanel(sessionId: widget.sessionId, teamId: runtime['team_id'] as String? ?? ''),
            ]));
          },
        ),
      );
}

class _ContextCard extends StatelessWidget {
  const _ContextCard({required this.runtime, required this.player});

  final Map<String, dynamic> runtime;
  final Map<String, dynamic>? player;

  @override
  Widget build(BuildContext context) {
    final roles = (runtime['roles'] as List<dynamic>?) ?? [];
    final role = roles.cast<Map<String, dynamic>>().firstWhere((item) => item['player_id'] == player?['id'], orElse: () => {});
    return Card(child: ListTile(leading: Icon(player?['support'] == true ? Icons.groups : Icons.person), title: Text(player?['support'] == true ? 'Support' : role['role_code'] as String? ?? 'بازیکن'), subtitle: Text('وضعیت تیم: ${runtime['status'] ?? ''}')));
  }
}
