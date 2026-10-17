import 'package:flutter/material.dart';

import '../../app/nexo_components.dart';
import '../../core/api/api_client.dart';
import '../../core/auth/auth_controller.dart';
import '../rooms/room_pages.dart';

class GamesPage extends StatefulWidget {
  const GamesPage({required this.auth, super.key});

  final AuthController auth;

  @override
  State<GamesPage> createState() => _GamesPageState();
}

class _GamesPageState extends State<GamesPage> {
  late Future<List<Map<String, dynamic>>> games = _load();

  Future<List<Map<String, dynamic>>> _load() async {
    final response = await widget.auth.api.get('/games');
    return (response['items'] as List? ?? const [])
        .whereType<Map<String, dynamic>>()
        .where((game) => game['status'] == 'ACTIVE')
        .toList(growable: false);
  }

  Future<void> _refresh() async {
    setState(() => games = _load());
    try {
      await games;
    } catch (_) {
      // The error view provides the retry action.
    }
  }

  @override
  Widget build(BuildContext context) => Scaffold(
        appBar: AppBar(title: const Text('بازی‌ها')),
        body: SafeArea(
          child: FutureBuilder<List<Map<String, dynamic>>>(
            future: games,
            builder: (context, snapshot) {
              if (snapshot.connectionState != ConnectionState.done &&
                  !snapshot.hasData) {
                return const NexoLoading(message: 'در حال دریافت بازی‌ها…');
              }
              if (snapshot.hasError && !snapshot.hasData) {
                final offline = ApiClient.isNetworkFailure(snapshot.error!);
                return NexoErrorView(
                  message: offline
                      ? 'برای دیدن بازی‌ها به اینترنت وصل شوید.'
                      : 'دریافت بازی‌ها انجام نشد. دوباره تلاش کنید.',
                  offline: offline,
                  onRetry: _refresh,
                );
              }

              final availableGames = snapshot.data ?? const [];
              return RefreshIndicator(
                onRefresh: _refresh,
                child: availableGames.isEmpty
                    ? ListView(
                        physics: const AlwaysScrollableScrollPhysics(),
                        padding: const EdgeInsets.all(24),
                        children: const [
                          SizedBox(height: 100),
                          NexoEmptyView(
                            title: 'بازی‌ای در دسترس نیست',
                            message: 'بازی‌های منتشرشده در این بخش نمایش داده می‌شوند.',
                            icon: Icons.explore_outlined,
                          ),
                        ],
                      )
                    : ListView.separated(
                        physics: const AlwaysScrollableScrollPhysics(),
                        padding: const EdgeInsets.all(16),
                        itemCount: availableGames.length,
                        separatorBuilder: (_, __) => const SizedBox(height: 8),
                        itemBuilder: (context, index) {
                          final game = availableGames[index];
                          final city = (game['city'] as String? ?? '').trim();
                          return Card(
                            child: ListTile(
                              leading: const CircleAvatar(
                                child: Icon(Icons.explore_outlined),
                              ),
                              title: Text(
                                game['name'] as String? ?? 'بازی',
                                maxLines: 1,
                                overflow: TextOverflow.ellipsis,
                              ),
                              subtitle: city.isEmpty ? null : Text(city),
                              trailing: const Icon(Icons.chevron_left),
                              onTap: () => Navigator.of(context).push(
                                MaterialPageRoute<void>(
                                  builder: (_) =>
                                      RoomListPage(auth: widget.auth, game: game),
                                ),
                              ),
                            ),
                          );
                        },
                      ),
              );
            },
          ),
        ),
        bottomNavigationBar: const NexoBottomNavigation(selectedIndex: 1),
      );
}
