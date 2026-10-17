import 'dart:async';
import 'dart:convert';
import 'dart:io';

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';
import 'package:nexo_player/app/app.dart';
import 'package:nexo_player/core/api/api_client.dart';
import 'package:nexo_player/core/auth/auth_controller.dart';
import 'package:nexo_player/core/errors/api_error.dart';
import 'package:nexo_player/core/offline/offline_identity.dart';
import 'package:nexo_player/core/storage/secure_store.dart';

const deviceUuid = '9b93408e-778f-4619-b4f5-c01ea395b895';

void main() {
  testWidgets(
      'failed login preserves the form and displays the credential error',
      (tester) async {
    final response = Completer<http.Response>();
    final auth = AuthController(
        ApiClient(
            store: MemoryStore(),
            client: MockClient((request) => response.future)),
        identity: OfflineIdentity());
    await tester.pumpWidget(NexoApp(authController: auth));
    await tester.pumpAndSettle();
    await tester.enterText(find.byType(TextField).first, '09120000001');
    await tester.enterText(
        find.byType(TextField).last, 'invalid-test-password');
    await tester.tap(find.byType(FilledButton));
    await tester.pump();
    expect(find.byType(TextField), findsNWidgets(2));
    expect(tester.widget<FilledButton>(find.byType(FilledButton)).onPressed,
        isNull);

    response.complete(http.Response(
        jsonEncode({
          'error': {'code': 'AUTHENTICATION_REQUIRED', 'message': 'invalid'}
        }),
        401));
    await tester.pumpAndSettle();

    expect(find.text('شماره موبایل یا رمز عبور صحیح نیست'), findsOneWidget);
    expect(find.text('09120000001'), findsOneWidget);
    expect(tester.widget<FilledButton>(find.byType(FilledButton)).onPressed,
        isNotNull);
    expect(tester.takeException(), isNull);
    expect(auth.authenticated, isFalse);
  });

  test('valid envelope saves token and restores with the same device headers',
      () async {
    final store = MemoryStore();
    final api = ApiClient(
        store: store,
        client: MockClient((request) async {
          if (request.url.path.endsWith('/auth/login')) {
            expect(jsonDecode(request.body), {
              'mobile': '09120000001',
              'password': 'test-password',
              'device_uuid': deviceUuid,
              'device_name': 'NEXO Player',
              'platform': 'android',
              'app_version': '0.1.0',
            });
            return http.Response(
                jsonEncode({
                  'data': {
                    'token': 'test-token',
                    'user': {'id': 'public-user', 'name': 'Test Player'},
                    'device': {'device_uuid': deviceUuid}
                  }
                }),
                200);
          }
          expect(request.url.path, endsWith('/me'));
          expect(request.headers['Authorization'], 'Bearer test-token');
          expect(request.headers['X-Device-Id'], deviceUuid);
          return http.Response(
              '{"data":{"id":"public-user","name":"Test Player"}}', 200);
        }));
    final auth = AuthController(api, identity: OfflineIdentity());

    await auth.login('09120000001', 'test-password');
    expect(auth.authenticated, isTrue);
    expect(await api.token(), 'test-token');
    final restored = AuthController(api, identity: OfflineIdentity());
    await restored.restore();
    expect(restored.user?['id'], 'public-user');
    expect(restored.identity.owner?.deviceUuid, deviceUuid);
    auth.dispose();
    restored.dispose();
  });

  test('network failures produce a safe visible message', () async {
    final auth = AuthController(ApiClient(
        store: MemoryStore(),
        client: MockClient(
            (_) async => throw const SocketException('private diagnostic'))));
    await expectLater(
        auth.login('09120000001', 'test-password'), throwsA(isA<ApiError>()));
    expect(auth.error?.userMessage, 'ارتباط با سرور برقرار نشد');
    expect(auth.signingIn, isFalse);
    expect(auth.authenticated, isFalse);
    auth.dispose();
  });

  testWidgets('login timeout displays an error and allows another attempt',
      (tester) async {
    final auth = AuthController(
        ApiClient(
            store: MemoryStore(),
            client: MockClient(
                (_) async => throw TimeoutException('private diagnostic'))),
        identity: OfflineIdentity());
    await tester.pumpWidget(NexoApp(authController: auth));
    await tester.pumpAndSettle();
    await tester.enterText(find.byType(TextField).first, '09120000001');
    await tester.enterText(find.byType(TextField).last, 'test-password');
    await tester.tap(find.byType(FilledButton));
    await tester.pumpAndSettle();

    expect(find.text('ارتباط با سرور برقرار نشد'), findsOneWidget);
    expect(find.byType(TextField), findsNWidgets(2));
    expect(tester.widget<FilledButton>(find.byType(FilledButton)).onPressed,
        isNotNull);
    expect(auth.authenticated, isFalse);
    expect(tester.takeException(), isNull);
  });
  test('unreadable token never authenticates the user', () async {
    final auth = AuthController(ApiClient(
        store: MemoryStore(dropToken: true),
        client: MockClient((_) async => http.Response(
            jsonEncode({
              'data': {
                'token': 'test-token',
                'user': {'id': 'public-user', 'name': 'Test Player'},
                'device': {'device_uuid': deviceUuid}
              }
            }),
            200))));
    await expectLater(
        auth.login('09120000001', 'test-password'), throwsA(isA<ApiError>()));
    expect(auth.error?.code, 'AUTH_STORAGE_FAILED');
    expect(auth.authenticated, isFalse);
    auth.dispose();
  });
}

class MemoryStore extends SecureStore {
  MemoryStore({this.dropToken = false});
  final bool dropToken;
  final values = <String, String>{'nexo.device_id': deviceUuid};
  @override
  Future<String?> read(String key) async => values[key];
  @override
  Future<void> write(String key, String value) async {
    if (!(dropToken && key == 'nexo.access_token')) values[key] = value;
  }

  @override
  Future<void> delete(String key) async {
    values.remove(key);
  }
}
