import 'dart:async';

import 'package:flutter/material.dart';
import 'package:uuid/uuid.dart';

import '../../core/api/api_client.dart';
import '../../core/errors/api_error.dart';
import '../../core/offline/offline_identity.dart';
import 'evidence_device.dart';
import 'evidence_gateway.dart';
import 'private_media.dart';

class EvidenceCard extends StatefulWidget {
  const EvidenceCard(
      {required this.node,
      required this.sessionId,
      this.onChanged,
      this.device,
      this.gateway,
      super.key});
  final Map<String, dynamic> node;
  final String sessionId;
  final Future<void> Function()? onChanged;
  final EvidenceDevice? device;
  final EvidenceGateway? gateway;
  @override
  State<EvidenceCard> createState() => _EvidenceCardState();
}

class _EvidenceCardState extends State<EvidenceCard>
    with WidgetsBindingObserver {
  late final device = widget.device ?? NativeEvidenceDevice();
  late final gateway = widget.gateway ?? EvidenceGateway();
  final startKey = const Uuid().v4();
  CapturedEvidence? selected;
  int? selectedGeneration;
  int? recordingGeneration;
  String uploadKey = const Uuid().v4();
  Map<String, dynamic>? submitted;
  bool started = false;
  bool busy = false;
  bool recording = false;
  int seconds = 0;
  Timer? timer;
  Timer? stopTimer;
  final submittedFile = PrivateMediaFile();
  String? error;
  Map<String, dynamic> get config =>
      widget.node['config'] as Map<String, dynamic>? ?? {};
  int get maxBytes =>
      ((config['max_size_mb'] as num?)?.toInt() ?? 25) * 1024 * 1024;
  String get type => widget.node['node_type'] as String;
  Map<String, dynamic>? get submission =>
      submitted ?? config['submission'] as Map<String, dynamic>?;

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addObserver(this);
  }

  @override
  void didUpdateWidget(covariant EvidenceCard oldWidget) {
    super.didUpdateWidget(oldWidget);
    final server = config['submission'] as Map<String, dynamic>?;
    final previous = (oldWidget.node['config']
        as Map<String, dynamic>?)?['submission'] as Map<String, dynamic>?;
    if (server != null &&
        (submitted == null ||
            server['id'] == submitted?['id'] ||
            server['id'] != previous?['id'])) {
      submitted = server;
    }
  }

  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    if (state != AppLifecycleState.resumed && recording && !busy) run(stop);
  }

  @override
  void dispose() {
    timer?.cancel();
    stopTimer?.cancel();
    WidgetsBinding.instance.removeObserver(this);
    unawaited(device.dispose());
    unawaited(submittedFile.dispose());
    super.dispose();
  }

  Future<void> ensureStarted() async {
    if (!started && widget.node['status'] == 'AVAILABLE') {
      await gateway.start(widget.node['id'] as String, startKey);
    }
    started = true;
  }

  Future<void> run(Future<void> Function() action) async {
    if (busy) return;
    setState(() {
      busy = true;
      error = null;
    });
    try {
      await action();
    } catch (failure) {
      if (mounted) {
        setState(() => error = ApiClient.isNetworkFailure(failure)
            ? 'برای ارسال این مرحله به اینترنت نیاز دارید.'
            : failure is FormatException ||
                    failure is ApiError && failure.code == 'VALIDATION_FAILED'
                ? 'نوع، اندازه یا مدت فایل معتبر نیست؛ محدودیت مرحله را بررسی کنید.'
                : failure is ApiError
                    ? failure.userMessage
                    : 'عملیات انجام نشد. دسترسی دوربین یا میکروفون و فایل را بررسی کنید.');
      }
    } finally {
      if (mounted) setState(() => busy = false);
    }
  }

  void choose(CapturedEvidence? value) {
    if (!mounted || value == null) return;
    if (value.bytes.isEmpty || value.bytes.length > maxBytes) {
      throw const FormatException('size');
    }
    setState(() {
      selected = value;
      selectedGeneration = OfflineIdentity.instance.generation;
      uploadKey = const Uuid().v4();
    });
  }

  Future<void> capture(bool camera) async {
    final generation = OfflineIdentity.instance.generation;
    await ensureStarted();
    final value = await device.photo(camera: camera, maxBytes: maxBytes);
    if (generation == OfflineIdentity.instance.generation) choose(value);
  }

  Future<void> pick() async {
    final generation = OfflineIdentity.instance.generation;
    await ensureStarted();
    final types = type == 'AudioSubmission'
        ? ['audio/wav']
        : (config['allowed_mime_types'] as List? ?? []).cast<String>();
    final value = await device.file(types, maxBytes);
    if (generation == OfflineIdentity.instance.generation) choose(value);
  }

  Future<void> record() async {
    recordingGeneration = OfflineIdentity.instance.generation;
    await ensureStarted();
    if (!mounted ||
        recordingGeneration != OfflineIdentity.instance.generation) {
      return;
    }
    await device.startRecording();
    if (!mounted) return;
    if (recordingGeneration != OfflineIdentity.instance.generation) {
      await device.stopRecording(maxBytes);
      return;
    }
    setState(() {
      recording = true;
      seconds = 0;
      selected = null;
    });
    final duration = (config['max_duration_seconds'] as num?)?.toInt() ?? 30;
    // Leave room for native recorder startup/finalization within the server limit.
    stopTimer =
        Timer(Duration(milliseconds: duration * 1000 - 500), () => run(stop));
    timer = Timer.periodic(const Duration(seconds: 1), (_) {
      if (!mounted) return;
      setState(() => seconds++);
      final duration = (config['max_duration_seconds'] as num?)?.toInt() ?? 30;
      if (seconds >= duration || seconds * 32000 + 44 >= maxBytes) {
        timer?.cancel();
        run(stop);
      }
    });
  }

  Future<void> stop() async {
    timer?.cancel();
    stopTimer?.cancel();
    try {
      final value = await device.stopRecording(maxBytes);
      if (recordingGeneration == OfflineIdentity.instance.generation) {
        choose(value);
      }
    } finally {
      if (mounted) setState(() => recording = false);
    }
  }

  Future<void> upload() async {
    final evidence = selected;
    if (evidence == null) return;
    if (selectedGeneration != OfflineIdentity.instance.generation) {
      throw const ApiError(code: 'AUTHENTICATION_REQUIRED', message: '');
    }
    final result = await gateway.upload(
        widget.node['id'] as String, widget.sessionId, evidence, uploadKey,
        requireLocation: config['require_location'] == true);
    if (!mounted) return;
    setState(() {
      submitted = result;
      selected = null;
    });
    await widget.onChanged?.call();
  }

  Future<void> openSubmitted() async {
    final asset = submission?['asset'] as Map<String, dynamic>?;
    final mime = asset?['mime_type'] as String?;
    if (!PrivateMediaFile.extensions.containsKey(mime)) {
      throw const FormatException('type');
    }
    final bytes = await gateway.api.downloadMedia(
        '/submissions/${submission!['id']}/download',
        expectedMime: mime);
    if (mounted) await submittedFile.open(bytes, mime!);
  }

  @override
  Widget build(BuildContext context) {
    final status = submission?['status'];
    final canUpload = status == null ||
        status == 'REJECTED' && config['allow_resubmit'] == true;
    return Card(
        child: Padding(
            padding: const EdgeInsets.all(16),
            child: Column(
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: [
                  Text(config['instruction'] as String? ?? ''),
                  const SizedBox(height: 12),
                  if (status != null)
                    Text(switch (status) {
                      'PENDING' => 'مدرک ارسال شد؛ در انتظار بررسی GameMaster',
                      'APPROVED' => 'مدرک تأیید شد؛ منتظر مرحله بعد بمانید.',
                      _ => 'مدرک رد شد.'
                    }),
                  if (submission?['review_message'] is String)
                    Text(submission!['review_message'] as String),
                  if (submission?['asset'] is Map &&
                      submission?['can_download'] != false)
                    OutlinedButton(
                        onPressed: busy ? null : () => run(openSubmitted),
                        child: const Text('دریافت مدرک ارسال‌شده')),
                  if (canUpload) ...[
                    Text('حداکثر اندازه: ${config['max_size_mb']} مگابایت'),
                    if (type == 'AudioSubmission')
                      Text(
                          'حداکثر مدت: ${config['max_duration_seconds']} ثانیه • WAV'),
                    if (type == 'FileSubmission')
                      Text((config['allowed_mime_types'] as List? ?? [])
                          .join('، ')),
                    const Text('برای ارسال این مرحله به اینترنت نیاز دارید.'),
                    const SizedBox(height: 12),
                    if (type == 'PhotoSubmission') ...[
                      OutlinedButton.icon(
                          onPressed:
                              busy ? null : () => run(() => capture(true)),
                          icon: const Icon(Icons.camera_alt),
                          label: const Text('گرفتن عکس')),
                      if (['CAMERA_OR_GALLERY', 'CAMERA_OR_LIBRARY']
                          .contains(config['camera_policy']))
                        OutlinedButton(
                            onPressed:
                                busy ? null : () => run(() => capture(false)),
                            child: const Text('انتخاب از گالری')),
                    ],
                    if (type == 'AudioSubmission') ...[
                      OutlinedButton.icon(
                          onPressed: busy
                              ? null
                              : () => run(recording ? stop : record),
                          icon: Icon(recording ? Icons.stop : Icons.mic),
                          label: Text(recording
                              ? 'پایان ضبط • $seconds ثانیه'
                              : selected == null
                                  ? 'ضبط صدا'
                                  : 'ضبط دوباره')),
                      if (config['record_policy'] == 'RECORD_OR_LIBRARY' &&
                          !recording)
                        OutlinedButton(
                            onPressed: busy ? null : () => run(pick),
                            child: const Text('انتخاب فایل WAV')),
                    ],
                    if (type == 'FileSubmission')
                      OutlinedButton(
                          onPressed: busy ? null : () => run(pick),
                          child: const Text('انتخاب فایل')),
                    if (selected != null) ...[
                      Text(selected!.filename),
                      if (type == 'PhotoSubmission')
                        Image.memory(selected!.bytes,
                            height: 180,
                            fit: BoxFit.contain,
                            semanticLabel: 'پیش‌نمایش عکس انتخاب‌شده',
                            errorBuilder: (_, error, stack) =>
                                const Text('پیش‌نمایش عکس در دسترس نیست.')),
                      if (type == 'AudioSubmission')
                        PrivateAudioPlayer(
                            key: ValueKey(uploadKey),
                            load: () async => selected!.bytes,
                            mime: 'audio/wav'),
                      FilledButton(
                          onPressed:
                              busy || recording ? null : () => run(upload),
                          child: const Text('ارسال مدرک')),
                    ],
                  ],
                  if (busy) const LinearProgressIndicator(),
                  if (error != null)
                    Padding(
                        padding: const EdgeInsets.only(top: 12),
                        child: Text(error!,
                            style: TextStyle(
                                color: Theme.of(context).colorScheme.error))),
                ])));
  }
}
