import 'package:flutter/material.dart';

import '../../core/api/api_client.dart';
import '../../core/auth/auth_controller.dart';
import '../runtime/gameplay_page.dart';

class HomePage extends StatefulWidget {
  const HomePage({required this.auth, super.key});

  final AuthController auth;

  @override
  State<HomePage> createState() => _HomePageState();
}

class _HomePageState extends State<HomePage> {
  late Future<Map<String, dynamic>> sessions;

  @override
  void initState() {
    super.initState();
    sessions = ApiClient().get('/game-sessions');
  }

  @override
  Widget build(BuildContext context) => Scaffold(
        appBar: AppBar(title: const Text('بازی‌های من'), actions: [IconButton(onPressed: widget.auth.logout, icon: const Icon(Icons.logout))]),
        body: FutureBuilder<Map<String, dynamic>>(
          future: sessions,
          builder: (context, snapshot) {
            if (snapshot.connectionState != ConnectionState.done) return const Center(child: CircularProgressIndicator());
            if (snapshot.hasError) return _ErrorView(onRetry: () => setState(() => sessions = ApiClient().get('/game-sessions')));
            final data = snapshot.data ?? {};
            final items = (data['data'] ?? data['items'] ?? data['sessions'] ?? []) as List<dynamic>;
            if (items.isEmpty) return const Center(child: Text('جلسه فعالی پیدا نشد.'));
            return RefreshIndicator(
              onRefresh: () async => setState(() => sessions = ApiClient().get('/game-sessions')),
              child: ListView.builder(
                padding: const EdgeInsets.all(16),
                itemCount: items.length,
                itemBuilder: (context, index) {
                  final session = items[index] as Map<String, dynamic>;
                  final id = session['id'] as String?;
                  return Card(child: ListTile(title: Text(session['name'] as String? ?? 'جلسه بازی'), subtitle: Text(session['status'] as String? ?? ''), trailing: const Icon(Icons.chevron_left), onTap: id == null ? null : () => Navigator.push(context, MaterialPageRoute(builder: (_) => GameplayPage(sessionId: id)))));
                },
              ),
            );
          },
        ),
      );
}

class _ErrorView extends StatelessWidget {
  const _ErrorView({required this.onRetry});

  final VoidCallback onRetry;

  @override
  Widget build(BuildContext context) => Center(child: Column(mainAxisSize: MainAxisSize.min, children: [const Text('اتصال برقرار نشد.'), const SizedBox(height: 12), OutlinedButton(onPressed: onRetry, child: const Text('تلاش دوباره'))]));
}
