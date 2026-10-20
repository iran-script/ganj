import 'dart:convert';

import 'package:flutter/material.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

import 'app/app.dart';
import 'core/api/api_client.dart';
import 'core/auth/auth_controller.dart';
import 'core/offline/local_database.dart';
import 'core/storage/secure_store.dart';

void main() {
  WidgetsFlutterBinding.ensureInitialized();
  final store = _PreviewSecureStore()
    ..values['nexo.access_token'] = 'local-preview-token';
  final api = ApiClient(store: store, client: MockClient(_previewResponse));
  final auth =
      AuthController(api, database: LocalDatabase(databasePath: ':memory:'));
  runApp(_PreviewApp(auth: auth));
}

Future<http.Response> _previewResponse(http.Request request) async {
  final path = request.url.path.replaceFirst('/api/v1', '');
  final payload = switch (path) {
    '/me' => {
        'id': 'preview-player',
        'name': 'بازیکن نمونه',
        'email': 'preview@nexo.local',
        'mobile': null,
        'status': 'ACTIVE',
        'roles': ['PLAYER'],
      },
    '/games' => {
        'items': [
          {
            'id': 'game-preview',
            'name': 'رد پای شهر',
            'city': 'تهران',
            'status': 'ACTIVE'
          },
          {
            'id': 'game-preview-2',
            'name': 'راز میدان',
            'city': 'شیراز',
            'status': 'ACTIVE'
          },
        ],
      },
    '/game-sessions' => {
        'items': [_session]
      },
    '/teams' => {
        'items': [_team]
      },
    '/game-sessions/session-preview/players/me/runtime' => _runtime,
    '/game-sessions/session-preview/state' => {'status': 'RUNNING'},
    '/game-sessions/session-preview/teams/team-preview/score' => {
        'score': 1280,
        'hints_used': 1,
        'effective_elapsed_seconds': 740,
      },
    '/game-sessions/session-preview/leaderboard' => {
        'rankings': [
          {
            'rank': 1,
            'score': 1510,
            'own_team': false,
            'effective_elapsed_seconds': 690
          },
          {
            'rank': 2,
            'score': 1280,
            'own_team': true,
            'effective_elapsed_seconds': 740
          },
          {
            'rank': 3,
            'score': 1110,
            'own_team': false,
            'effective_elapsed_seconds': 800
          },
        ],
      },
    _ => <String, dynamic>{'items': <dynamic>[]},
  };
  return http.Response(jsonEncode(payload), 200,
      headers: {'content-type': 'application/json; charset=utf-8'});
}

final Map<String, dynamic> _session = {
  'id': 'session-preview',
  'game_id': 'game-preview',
  'status': 'RUNNING',
  'teams': [_team],
};

final Map<String, dynamic> _team = {
  'id': 'team-preview',
  'team_id': 'team-preview',
  'name': 'پیشتازان نکسو',
  'status': 'ACTIVE',
  'captain_user_id': 'preview-player',
  'captain': {'name': 'بازیکن نمونه'},
  'members': [
    {'user_id': 'preview-player', 'name': 'بازیکن نمونه', 'status': 'ACTIVE'},
    {'user_id': 'player-two', 'name': 'هم‌تیمی', 'status': 'ACTIVE'},
  ],
  'eligible_for_game': true,
  'players': [
    {
      'user': {'id': 'preview-player'},
      'user_id': 'preview-player',
      'name': 'بازیکن نمونه',
      'status': 'ACTIVE',
    },
    {
      'user': {'id': 'player-two'},
      'user_id': 'player-two',
      'name': 'هم‌تیمی',
      'status': 'ACTIVE',
    },
  ],
};

final Map<String, dynamic> _runtime = {
  'status': 'IN_PROGRESS',
  'team_id': 'team-preview',
  'active_node_ids': ['node-preview-1'],
  'nodes': [
    {
      'id': 'node-preview-1',
      'title': 'نشان پنهان میدان',
      'node_type': 'GPS / Location',
      'status': 'AVAILABLE',
      'config': {
        // This illustrative point is only used by lib/ui_preview.dart.
        'latitude': 35.6998,
        'longitude': 51.3370,
        'radius_m': 50,
        'prompt': 'به نقطهٔ مشخص‌شده برسید و موقعیت را بررسی کنید.',
      },
    },
    {
      'id': 'node-preview-2',
      'title': 'رمز گذرگاه',
      'node_type': 'Puzzle',
      'status': 'LOCKED',
      'config': {'prompt': 'رمز مرحله را پیدا کنید.'},
    },
  ],
};

class _PreviewSecureStore extends SecureStore {
  _PreviewSecureStore() : super();

  final Map<String, String> values = {};

  @override
  Future<String?> read(String key) async => values[key];

  @override
  Future<void> write(String key, String value) async => values[key] = value;

  @override
  Future<void> delete(String key) async => values.remove(key);
}

class _PreviewApp extends StatelessWidget {
  const _PreviewApp({required this.auth});

  final AuthController auth;

  @override
  Widget build(BuildContext context) => NexoApp(
        authController: auth,
        showPreviewBanner: true,
      );
}
