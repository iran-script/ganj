import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:nexo_player/features/runtime/node_renderer.dart';
import 'package:nexo_player/features/runtime/media_reveal_card.dart';
import 'dart:convert';

void main() {
  for (final type in ['PhotoSubmission', 'AudioSubmission', 'FileSubmission']) {
    testWidgets(
        '$type displays review status without bypassing server approval',
        (tester) async {
      await tester.pumpWidget(MaterialApp(
          home: NodeRenderer(node: {
        'node_type': type,
        'config': {
          'instruction': 'Evidence instruction',
          'submission': {'status': 'PENDING'},
          'allow_resubmit': true,
          'max_size_mb': 10,
        }
      }, sessionId: 'session', onSubmit: _noop)));
      expect(find.textContaining('در انتظار بررسی GameMaster'), findsOneWidget);
      expect(find.byType(FilledButton), findsNothing);
      expect(find.textContaining('هنوز فعال نیست'), findsNothing);
    });
  }
  testWidgets('rejected evidence shows review feedback and online-only policy',
      (tester) async {
    await tester.pumpWidget(const MaterialApp(
        home: NodeRenderer(node: {
      'node_type': 'PhotoSubmission',
      'config': {
        'submission': {'status': 'REJECTED', 'review_message': 'Try again'},
        'allow_resubmit': true,
        'max_size_mb': 10,
      }
    }, sessionId: 'session', onSubmit: _noop)));
    expect(find.text('Try again'), findsOneWidget);
    expect(find.text('برای ارسال این مرحله به اینترنت نیاز دارید.'),
        findsOneWidget);
    expect(find.byType(FilledButton), findsNothing);
  });
  testWidgets(
      'image reveal uses authenticated node media and remains readable on small screens',
      (tester) async {
    tester.view.resetPhysicalSize();
    tester.view.physicalSize = const Size(375, 750);
    tester.view.devicePixelRatio = 1;
    addTearDown(tester.view.resetPhysicalSize);
    addTearDown(tester.view.resetDevicePixelRatio);
    String? path;
    await tester.pumpWidget(MaterialApp(
        home: Scaffold(
            body: SingleChildScrollView(
                child: MediaRevealCard(
      node: {
        'id': 'node',
        'config': {
          'description': 'Image clue',
          'media': {'kind': 'IMAGE', 'name': 'Clue'}
        }
      },
      load: (value) async {
        path = value;
        return base64Decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+j2ioAAAAASUVORK5CYII=');
      },
      onContinue: () async {},
    )))));
    await tester.pumpAndSettle();
    expect(path, '/runtime/nodes/node/media');
    expect(find.byType(Image), findsOneWidget);
    expect(tester.takeException(), isNull);
  });
  for (final type in ['TeamTogether', 'Barrier', 'Relay']) {
    testWidgets('$type renders a server-authoritative waiting state',
        (tester) async {
      Map<String, dynamic>? evidence;
      await tester.pumpWidget(MaterialApp(
          home: Scaffold(
              body: NodeRenderer(
                  node: {'node_type': type, 'progress': 1, 'config': {}},
                  sessionId: 'session',
                  onSubmit: (value) async {
                    evidence = value;
                  }))));
      expect(find.text('پیشرفت: 1'), findsOneWidget);
      await tester.tap(find.text('ثبت مشارکت'));
      expect(evidence, {'acknowledged': true});
    });
  }
  testWidgets('Time delay never advances from the client clock',
      (tester) async {
    await tester.pumpWidget(const MaterialApp(
        home: NodeRenderer(node: {
      'node_type': 'Time',
      'config': {'mode': 'DELAY', 'duration_seconds': 30}
    }, sessionId: 'session', onSubmit: _noop)));
    expect(find.textContaining('زمان سرور'), findsOneWidget);
    expect(find.byType(FilledButton), findsNothing);
    await tester.pump(const Duration(minutes: 2));
    expect(find.byType(FilledButton), findsNothing);
  });
  testWidgets('SplitInformation renders only the projected clue',
      (tester) async {
    await tester.pumpWidget(const MaterialApp(
        home: NodeRenderer(node: {
      'node_type': 'SplitInformation',
      'config': {'text': 'Private navigator clue'}
    }, sessionId: 'session', onSubmit: _noop)));
    expect(find.text('Private navigator clue'), findsOneWidget);
  });
  testWidgets('logic nodes show a transition without client decision controls',
      (tester) async {
    await tester.pumpWidget(const MaterialApp(
        home: NodeRenderer(
            node: {'node_type': 'Condition', 'config': {}},
            sessionId: 'session',
            onSubmit: _noop)));
    expect(find.text('در انتظار مرحله بعد از سرور'), findsOneWidget);
    expect(find.byType(FilledButton), findsNothing);
  });
  testWidgets('unknown and AR nodes fail closed without crashing',
      (tester) async {
    await tester.pumpWidget(const MaterialApp(
        home: NodeRenderer(
            node: {'node_type': 'AR Challenge', 'config': {}},
            sessionId: 'session',
            onSubmit: _noop)));
    expect(find.text('قابلیت AR در این نسخه فعال نیست.'), findsOneWidget);
    await tester.pumpWidget(const MaterialApp(
        home: NodeRenderer(
            node: {'node_type': 'Future Node', 'config': {}},
            sessionId: 'session',
            onSubmit: _noop)));
    expect(find.text('محتوای این مرحله در نسخه فعلی پشتیبانی نمی‌شود.'),
        findsOneWidget);
  });
}

Future<void> _noop(Map<String, dynamic> _) async {}
