import 'dart:async';
import 'dart:convert';
import 'dart:typed_data';

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:nexo_player/features/runtime/evidence_card.dart';
import 'package:nexo_player/features/runtime/evidence_device.dart';
import 'package:nexo_player/features/runtime/evidence_gateway.dart';
import 'package:nexo_player/features/runtime/media_reveal_card.dart';

class FakeDevice implements EvidenceDevice {
  int recordings = 0;
  bool? camera;
  List<String>? allowed;
  bool disposed = false;
  final png = base64Decode(
      'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+j2ioAAAAASUVORK5CYII=');
  CapturedEvidence value(String name, String source) =>
      CapturedEvidence(png, name, source, DateTime.utc(2026, 10, 13));
  @override
  Future<CapturedEvidence?> photo(
      {required bool camera, required int maxBytes}) async {
    this.camera = camera;
    return value('photo.png', camera ? 'CAMERA' : 'LIBRARY');
  }

  @override
  Future<CapturedEvidence?> file(List<String> mimeTypes, int maxBytes) async {
    allowed = mimeTypes;
    return value('proof.pdf', 'LIBRARY');
  }

  @override
  Future<void> startRecording() async {
    recordings++;
  }

  @override
  Future<CapturedEvidence?> stopRecording(int maxBytes) async =>
      value('recording.wav', 'RECORDER');
  @override
  Future<void> dispose() async {
    disposed = true;
  }
}

class FakeGateway extends EvidenceGateway {
  int starts = 0;
  bool failFirst = false;
  final keys = <String>[];
  CapturedEvidence? received;
  bool? location;
  @override
  Future<void> start(String nodeId, String key) async {
    starts++;
  }

  @override
  Future<Map<String, dynamic>> upload(
      String nodeId, String sessionId, CapturedEvidence evidence, String key,
      {required bool requireLocation}) async {
    keys.add(key);
    received = evidence;
    location = requireLocation;
    if (failFirst && keys.length == 1) throw TimeoutException('offline');
    return {'id': 'submission', 'status': 'PENDING'};
  }
}

Future<void> mount(WidgetTester tester, String type,
        Map<String, dynamic> config, FakeDevice device, FakeGateway gateway) =>
    tester.pumpWidget(MaterialApp(
        home: Scaffold(
            body: SingleChildScrollView(
                child: EvidenceCard(node: {
      'id': 'node',
      'status': 'AVAILABLE',
      'node_type': type,
      'config': {
        'instruction': 'Proof',
        'max_size_mb': 10,
        'allow_resubmit': true,
        ...config
      }
    }, sessionId: 'session', device: device, gateway: gateway)))));

void main() {
  testWidgets(
      'camera-only policy hides gallery and starts the node before capture',
      (tester) async {
    final device = FakeDevice();
    final gateway = FakeGateway();
    await mount(
        tester,
        'PhotoSubmission',
        {'camera_policy': 'CAMERA_ONLY', 'require_location': true},
        device,
        gateway);
    expect(find.text('انتخاب از گالری'), findsNothing);
    expect(device.recordings, 0);
    await tester.tap(find.text('گرفتن عکس'));
    await tester.pumpAndSettle();
    expect(device.camera, true);
    expect(gateway.starts, 1);
    await tester.tap(find.text('ارسال مدرک'));
    await tester.pumpAndSettle();
    expect(gateway.received!.source, 'CAMERA');
    expect(gateway.location, true);
    expect(find.textContaining('در انتظار بررسی GameMaster'), findsOneWidget);
    expect(find.text('ارسال مدرک'), findsNothing);
  });

  testWidgets('gallery policy permits selection without recording permission',
      (tester) async {
    final device = FakeDevice();
    final gateway = FakeGateway();
    await mount(tester, 'PhotoSubmission',
        {'camera_policy': 'CAMERA_OR_GALLERY'}, device, gateway);
    await tester.tap(find.text('انتخاب از گالری'));
    await tester.pumpAndSettle();
    expect(device.camera, false);
    expect(device.recordings, 0);
  });

  testWidgets(
      'audio requests recording on tap, stops at limit, and offers playback and rerecord',
      (tester) async {
    final device = FakeDevice();
    final gateway = FakeGateway();
    await mount(
        tester,
        'AudioSubmission',
        {'record_policy': 'RECORD_ONLY', 'max_duration_seconds': 2},
        device,
        gateway);
    expect(device.recordings, 0);
    expect(find.text('انتخاب فایل WAV'), findsNothing);
    await tester.tap(find.text('ضبط صدا'));
    await tester.pump();
    expect(device.recordings, 1);
    await tester.pump(const Duration(seconds: 2));
    await tester.pumpAndSettle();
    expect(find.text('ضبط دوباره'), findsOneWidget);
    expect(find.text('پخش صدا'), findsOneWidget);
    await tester.tap(find.text('ارسال مدرک'));
    await tester.pumpAndSettle();
    expect(gateway.received!.source, 'RECORDER');
    await tester.pumpWidget(const SizedBox());
    await tester.pump();
    expect(device.disposed, true);
  });

  testWidgets(
      'file retry retains idempotency key and never claims queued upload',
      (tester) async {
    final device = FakeDevice();
    final gateway = FakeGateway()..failFirst = true;
    await mount(
        tester,
        'FileSubmission',
        {
          'allowed_mime_types': ['application/pdf']
        },
        device,
        gateway);
    await tester.tap(find.text('انتخاب فایل'));
    await tester.pumpAndSettle();
    expect(device.allowed, ['application/pdf']);
    expect(device.recordings, 0);
    await tester.tap(find.text('ارسال مدرک'));
    await tester.pumpAndSettle();
    expect(
        find.text('برای ارسال این مرحله به اینترنت نیاز دارید.'), findsWidgets);
    expect(find.textContaining('صف'), findsNothing);
    await tester.tap(find.text('ارسال مدرک'));
    await tester.pumpAndSettle();
    expect(gateway.keys, hasLength(2));
    expect(gateway.keys[0], gateway.keys[1]);
    expect(find.textContaining('در انتظار بررسی GameMaster'), findsOneWidget);
  });

  testWidgets('file reveal opens only after successful authorized download',
      (tester) async {
    final events = <String>[];
    await tester.pumpWidget(MaterialApp(
        home: Scaffold(
            body: MediaRevealCard(
                node: {
          'id': 'node',
          'config': {
            'media': {
              'kind': 'FILE',
              'mime_type': 'application/pdf',
              'name': 'Clue'
            }
          }
        },
                onContinue: () async {},
                load: (path) async {
                  events.add(path);
                  return Uint8List.fromList(utf8.encode('%PDF'));
                },
                openFile: (bytes, mime) async {
                  events.add(mime);
                }))));
    await tester.tap(find.text('دریافت و باز کردن فایل'));
    await tester.pumpAndSettle();
    expect(events, ['/runtime/nodes/node/media', 'application/pdf']);
  });

  testWidgets('failed authorized download never reaches external opener',
      (tester) async {
    bool opened = false;
    await tester.pumpWidget(MaterialApp(
        home: Scaffold(
            body: MediaRevealCard(
                node: {
          'id': 'node',
          'config': {
            'media': {'kind': 'FILE', 'mime_type': 'application/pdf'}
          }
        },
                onContinue: () async {},
                load: (_) async => throw StateError('unauthorized'),
                openFile: (_, mime) async {
                  opened = true;
                }))));
    await tester.tap(find.text('دریافت و باز کردن فایل'));
    await tester.pumpAndSettle();
    expect(opened, false);
    expect(find.textContaining('باز کردن فایل انجام نشد'), findsOneWidget);
  });
}
