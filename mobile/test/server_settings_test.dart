import 'dart:async';
import 'dart:convert';

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';
import 'package:nexo_player/core/api/api_client.dart';
import 'package:nexo_player/core/auth/auth_controller.dart';
import 'package:nexo_player/core/config/app_config.dart';
import 'package:nexo_player/core/config/server_connection_tester.dart';
import 'package:nexo_player/core/offline/local_database.dart';
import 'package:nexo_player/core/offline/offline_identity.dart';
import 'package:nexo_player/core/storage/secure_store.dart';
import 'package:nexo_player/features/auth/server_settings_page.dart';

class _Store extends SecureStore {
  final values = <String, String>{};

  @override
  Future<String?> read(String key) async => values[key];

  @override
  Future<void> write(String key, String value) async => values[key] = value;

  @override
  Future<void> delete(String key) async => values.remove(key);
}

class _Database extends LocalDatabase {
  @override
  Future<void> clearCache() async {}
}

AuthController _auth(_Store store) => AuthController(
      ApiClient(store: store),
      database: _Database(),
      identity: OfflineIdentity(),
    );

ServerConnectionTester _tester({
  Future<http.Response> Function(http.Request)? handler,
}) => ServerConnectionTester(
      client: MockClient(handler ?? (_) async => http.Response('{}', 200)),
    );

void main() {
  setUp(() async {
    await AppConfig.load(_Store());
  });

  test('first launch has no configured server and persistence survives reload',
      () async {
    final store = _Store();
    expect(await AppConfig.load(store), isFalse);

    await AppConfig.saveApiBaseUrl(store, 'http://192.168.1.100:8000/api');
    expect(AppConfig.apiBaseUrl, 'http://192.168.1.100:8000/api/v1');
    expect(await AppConfig.load(store), isTrue);
    expect(AppConfig.apiBaseUrl, 'http://192.168.1.100:8000/api/v1');
  });

  test('normalizes API paths without duplicating /api/v1', () {
    expect(AppConfig.normalizeApiBaseUrl('https://api.example.com'),
        'https://api.example.com/api/v1');
    expect(AppConfig.normalizeApiBaseUrl('https://api.example.com/api'),
        'https://api.example.com/api/v1');
    expect(AppConfig.normalizeApiBaseUrl('https://api.example.com/api/v1'),
        'https://api.example.com/api/v1');
    expect(AppConfig.normalizeApiBaseUrl('https://u:p@example.com'), isNull);
  });

  test('all ApiClient requests use the saved runtime base URL', () async {
    final store = _Store();
    await AppConfig.load(store);
    await AppConfig.saveApiBaseUrl(store, 'https://api.example.com');
    Uri? requested;
    final api = ApiClient(
      store: store,
      client: MockClient((request) async {
        requested = request.url;
        return http.Response(jsonEncode({'data': {}}), 200);
      }),
    );

    await api.get('/health');

    expect(requested, Uri.parse('https://api.example.com/api/v1/health'));
  });

  test('health endpoint accepts 401 as a reachable server', () async {
    await expectLater(
      _tester(handler: (_) async => http.Response('{}', 401))
          .test('https://api.example.com'),
      completes,
    );
  });

  test('connection timeout is classified for a useful UI message', () async {
    final tester = _tester(handler: (_) async => throw TimeoutException('slow'));

    await expectLater(
      tester.test('https://api.example.com'),
      throwsA(isA<ServerConnectionException>().having(
        (error) => error.kind,
        'kind',
        ServerConnectionFailure.timeout,
      )),
    );
  });

  test('changing server clears the previous token and session data', () async {
    final store = _Store()..values['nexo.access_token'] = 'old-token';
    final auth = _auth(store)..user = {'id': 'old-user'};

    await auth.resetForServerChange();

    expect(await auth.api.token(), isNull);
    expect(auth.user, isNull);
    auth.dispose();
  });

  testWidgets('server settings saves a tested address', (tester) async {
    final store = _Store();
    final auth = _auth(store);
    var saved = false;
    await tester.pumpWidget(MaterialApp(
      home: ServerSettingsPage(
        auth: auth,
        connectionTester: _tester(),
        onSaved: () async => saved = true,
      ),
    ));

    await tester.enterText(find.byType(TextField), 'https://api.example.com');
    await tester.tap(find.text('ذخیره آدرس سرور'));
    await tester.pumpAndSettle();

    expect(saved, isTrue);
    expect(AppConfig.apiBaseUrl, 'https://api.example.com/api/v1');
    expect(store.values['nexo.api_base_url'],
        'https://api.example.com/api/v1');
    auth.dispose();
  });
}
