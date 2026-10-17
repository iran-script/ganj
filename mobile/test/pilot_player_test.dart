import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';
import 'package:nexo_player/app/nexo_theme.dart';
import 'package:nexo_player/core/api/api_client.dart';
import 'package:nexo_player/core/errors/api_error.dart';
import 'package:nexo_player/core/offline/offline_sync_service.dart';
import 'package:nexo_player/core/offline/sync_state.dart';
import 'package:nexo_player/core/storage/secure_store.dart';
import 'package:nexo_player/features/runtime/gameplay_page.dart';
import 'package:nexo_player/features/runtime/node_renderer.dart';
import 'package:nexo_player/features/runtime/runtime_repository.dart';
import 'package:nexo_player/features/runtime/server_timer.dart';

Widget host(Widget child) => MaterialApp(
    theme: NexoTheme.data,
    home: Directionality(textDirection: TextDirection.rtl, child: child));

void main() {
  testWidgets('timer never invents elapsed time between server snapshots',
      (tester) async {
    await tester.pumpWidget(host(const Scaffold(
        body: ServerTimer(
            runtime: {'effective_elapsed_seconds': 462, 'status': 'ACTIVE'}))));
    expect(find.text('00:07:42'), findsOneWidget);
    await tester.pump(const Duration(seconds: 30));
    expect(find.text('00:07:42'), findsOneWidget);
    await tester.pumpWidget(host(const Scaffold(
        body: ServerTimer(
            runtime: {'effective_elapsed_seconds': 492, '_cached': true}))));
    expect(find.text('00:08:12'), findsOneWidget);
    expect(find.text('آخرین زمان تأییدشده تیم'), findsOneWidget);
  });
  testWidgets('barrier offers team contribution instead of AR', (tester) async {
    Map<String, dynamic>? evidence;
    await tester.pumpWidget(host(Scaffold(
        body: NodeRenderer(
            node: const {'node_type': 'Barrier', 'progress': 1},
            sessionId: 'session',
            onSubmit: (value) async {
              evidence = value;
            }))));
    expect(find.text('قابلیت AR در این نسخه فعال نیست.'), findsNothing);
    await tester.tap(find.text('ثبت مشارکت'));
    expect(evidence, {'acknowledged': true});
  });
  testWidgets('wrong answer stays on objective with visible rejection',
      (tester) async {
    final repository = PilotRuntime();
    await tester.pumpWidget(host(GameplayPage(
        sessionId: 'session',
        repository: repository,
        syncService: IdleSync(),
        api: _testApi())));
    await tester.pumpAndSettle();
    await tester.ensureVisible(find.text('ورود به مأموریت'));
    await tester.tap(find.text('ورود به مأموریت'));
    await tester.pumpAndSettle();
    await tester.enterText(find.byType(TextField), 'wrong');
    await tester.ensureVisible(find.text('ارسال'));
    await tester.tap(find.text('ارسال'));
    await tester.pumpAndSettle();
    expect(repository.submissions, 1);
    expect(find.text('مأموریت کامل شد'), findsNothing);
    expect(find.text('پاسخ تأیید نشد. دوباره بررسی کنید.'), findsWidgets);
    expect(tester.takeException(), isNull);
    await tester.pumpWidget(const SizedBox.shrink());
  });
  testWidgets('completion requires terminal server runtime', (tester) async {
    final repository = PilotRuntime()..completed = true;
    await tester.pumpWidget(host(GameplayPage(
        sessionId: 'session',
        repository: repository,
        syncService: IdleSync(),
        api: _testApi())));
    await tester.pumpAndSettle();
    expect(find.text('مأموریت کامل شد'), findsOneWidget);
    expect(find.text('ورود به مأموریت'), findsNothing);
    expect(repository.submissions, 0);
    await tester.pumpWidget(const SizedBox.shrink());
  });
  testWidgets('large Persian text fits a small landscape timer',
      (tester) async {
    tester.view.physicalSize = const Size(640, 375);
    tester.view.devicePixelRatio = 1;
    addTearDown(tester.view.resetPhysicalSize);
    addTearDown(tester.view.resetDevicePixelRatio);
    await tester.pumpWidget(host(const MediaQuery(
        data: MediaQueryData(textScaler: TextScaler.linear(2)),
        child: Scaffold(
            body: SingleChildScrollView(
                child: ServerTimer(runtime: {
          'effective_elapsed_seconds': 98236,
          '_cached': true
        }))))));
    expect(tester.takeException(), isNull);
    expect(find.text('27:17:16'), findsOneWidget);
  });
  test('unknown server and replay errors hide raw diagnostics', () {
    expect(
        const ApiError(code: 'UNEXPECTED', message: 'private detail')
            .userMessage,
        'خطایی رخ داد. دوباره تلاش کنید.');
    final status = SyncStatus()
      ..setState(SyncState.syncError, message: 'private detail');
    expect(status.userMessage, isNot(contains('private')));
    status.setState(SyncState.syncError, message: 'SESSION_FINISHED');
    expect(status.userMessage, contains('ارسال نمی‌شوند'));
  });
}

class IdleSync extends OfflineSyncService {
  @override
  Future<void> sync() async {}
}

ApiClient _testApi() => ApiClient(
    store: _TestStore(),
    client: MockClient((_) async => http.Response(
        '{"data":{"score":0,"hints_used":0,"effective_elapsed_seconds":0}}',
        200)));

class _TestStore extends SecureStore {
  @override
  Future<String?> read(String key) async =>
      key == 'nexo.device_id' ? '00000000-0000-4000-8000-000000000001' : null;
}

class PilotRuntime extends RuntimeRepository {
  bool completed = false;
  int submissions = 0;
  @override
  Future<Map<String, dynamic>> projection(String sessionId) async => {
        'session_id': sessionId,
        'team_id': 'team',
        'status': completed ? 'COMPLETED' : 'ACTIVE',
        'effective_elapsed_seconds': 462,
        'active_node_ids': completed ? [] : ['node'],
        'nodes': [
          {
            'id': 'node',
            'node_type': 'Puzzle',
            'status': 'ACTIVE',
            'title': 'رمز در سایه',
            'config': {'prompt': 'پاسخ را وارد کنید.'}
          }
        ],
      };
  @override
  Future<Map<String, dynamic>> submitWithOfflineFallback(
      {required String sessionId,
      required String nodeId,
      required Map<String, dynamic> evidence,
      required bool delayedValidationAllowed,
      required String nodeType,
      String? teamId,
      String? idempotencyKey}) async {
    submissions++;
    return {'accepted': false};
  }
}
