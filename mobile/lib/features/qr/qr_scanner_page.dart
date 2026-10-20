import 'dart:math';

import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:geolocator/geolocator.dart';
import 'package:mobile_scanner/mobile_scanner.dart';
import 'package:uuid/uuid.dart';

import '../../app/nexo_theme.dart';
import '../../core/api/api_client.dart';
import '../../core/errors/api_error.dart';

class QrScannerPage extends StatefulWidget {
  const QrScannerPage(
      {required this.sessionId,
      required this.onValue,
      this.api,
      this.scannerBuilder,
      super.key});
  final String sessionId;
  final Future<void> Function(String value) onValue;
  final ApiClient? api;
  final Widget Function(Future<void> Function(String? value) onDetected)?
      scannerBuilder;
  @override
  State<QrScannerPage> createState() => _QrScannerPageState();
}

class _QrScannerPageState extends State<QrScannerPage>
    with WidgetsBindingObserver {
  bool handled = false;
  bool confirmed = false;
  String? error;
  String? pendingValue;
  String? pendingRequestId;
  int cameraRevision = 0;
  late final ApiClient api = widget.api ?? ApiClient();

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addObserver(this);
  }

  @override
  void dispose() {
    WidgetsBinding.instance.removeObserver(this);
    super.dispose();
  }

  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    if (state == AppLifecycleState.resumed && mounted && !handled) {
      setState(() => cameraRevision++);
    }
  }

  Future<void> found(String? value) async {
    if (handled || value == null || value.isEmpty || error != null) return;
    setState(() => handled = true);
    if (pendingValue != value) {
      pendingValue = value;
      pendingRequestId = const Uuid().v4();
    }
    try {
      final result = await api.post('/qr/scan',
          device: true,
          idempotent: true,
          idempotencyKey: pendingRequestId,
          body: {'session_id': widget.sessionId, 'qr_identifier': value});
      final token = result['one_time_token'];
      if (result['accepted'] != true || token is! String || token.isEmpty) {
        throw const ApiError(code: 'QR_INVALID', message: '');
      }
      await widget.onValue(token);
      HapticFeedback.mediumImpact();
      pendingValue = null;
      pendingRequestId = null;
      if (mounted) setState(() => confirmed = true);
    } catch (failure) {
      if (mounted) {
        setState(() {
          error = failure is ApiError
              ? failure.userMessage
              : 'برای تأیید QR اتصال اینترنت لازم است.';
          handled = false;
        });
      }
    }
  }

  @override
  Widget build(BuildContext context) => Scaffold(
      appBar: AppBar(title: const Text('نشان مخفی · QR')),
      body: SafeArea(child: LayoutBuilder(builder: (context, constraints) {
        final size =
            min(constraints.maxWidth * .72, constraints.maxHeight * .45);
        final frame = Rect.fromCenter(
            center:
                Offset(constraints.maxWidth / 2, constraints.maxHeight * .4),
            width: size,
            height: size);
        final scanner = widget.scannerBuilder?.call(found) ??
            MobileScanner(
                key: ValueKey(cameraRevision),
                scanWindow: frame,
                errorBuilder: (context, failure, child) => Center(
                    child: Padding(
                        padding: const EdgeInsets.all(24),
                        child:
                            Column(mainAxisSize: MainAxisSize.min, children: [
                          const NexoNotice(
                              'برای اسکن، دسترسی دوربین را فعال کنید.',
                              icon: Icons.camera_alt_outlined),
                          FilledButton(
                              onPressed: () => Geolocator.openAppSettings(),
                              child: const Text('تنظیمات دسترسی دوربین')),
                          TextButton(
                              onPressed: () => setState(() => cameraRevision++),
                              child: const Text('تلاش دوباره')),
                        ]))),
                onDetect: (capture) =>
                    found(capture.barcodes.firstOrNull?.rawValue));
        return Stack(children: [
          if (!confirmed) scanner,
          if (!confirmed)
            IgnorePointer(
                child: CustomPaint(
                    size: Size(constraints.maxWidth, constraints.maxHeight),
                    painter: _ScannerFrame(frame))),
          if (confirmed)
            const Center(
                child: Icon(Icons.check_circle_outline,
                    size: 96, color: NexoTheme.success)),
          Align(
              alignment: Alignment.bottomCenter,
              child: SingleChildScrollView(
                  padding: const EdgeInsets.all(24),
                  child: Column(mainAxisSize: MainAxisSize.min, children: [
                    if (handled && !confirmed)
                      const CircularProgressIndicator(),
                    if (handled && !confirmed) const SizedBox(height: 16),
                    NexoNotice(
                        confirmed
                            ? 'اسکن و اقدام شما توسط سرور تأیید شد.'
                            : error ??
                                (handled
                                    ? 'در حال بررسی کد…'
                                    : 'QR را در کادر قرار دهید'),
                        icon: confirmed
                            ? Icons.check_circle_outline
                            : Icons.qr_code_scanner,
                        color: confirmed
                            ? NexoTheme.success
                            : error != null
                                ? NexoTheme.danger
                                : NexoTheme.amber),
                    if (confirmed)
                      FilledButton(
                          onPressed: () => Navigator.pop(context),
                          child: const Text('ادامه بازی')),
                    if (error != null)
                      FilledButton(
                          onPressed: () => setState(() => error = null),
                          child: const Text('اسکن دوباره')),
                  ]))),
        ]);
      })));
}

class _ScannerFrame extends CustomPainter {
  const _ScannerFrame(this.frame);
  final Rect frame;
  @override
  void paint(Canvas canvas, Size size) {
    final outside = Path()..addRect(Offset.zero & size);
    final inside = Path()
      ..addRRect(RRect.fromRectAndRadius(frame, const Radius.circular(24)));
    canvas.drawPath(Path.combine(PathOperation.difference, outside, inside),
        Paint()..color = Colors.black.withValues(alpha: .55));
    canvas.drawRRect(
        RRect.fromRectAndRadius(frame, const Radius.circular(24)),
        Paint()
          ..color = NexoTheme.amber
          ..strokeWidth = 3
          ..style = PaintingStyle.stroke);
  }

  @override
  bool shouldRepaint(_ScannerFrame oldDelegate) => oldDelegate.frame != frame;
}
