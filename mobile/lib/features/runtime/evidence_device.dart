import 'dart:io';
import 'dart:typed_data';

import 'package:file_picker/file_picker.dart';
import 'package:image_picker/image_picker.dart';
import 'package:path_provider/path_provider.dart';
import 'package:record/record.dart';

class CapturedEvidence {
  CapturedEvidence(this.bytes, this.filename, this.source, this.capturedAt);
  final Uint8List bytes;
  final String filename;
  final String source;
  final DateTime capturedAt;
}

abstract class EvidenceDevice {
  Future<CapturedEvidence?> photo(
      {required bool camera, required int maxBytes});
  Future<CapturedEvidence?> file(List<String> mimeTypes, int maxBytes);
  Future<void> startRecording();
  Future<CapturedEvidence?> stopRecording(int maxBytes);
  Future<void> dispose();
}

class NativeEvidenceDevice implements EvidenceDevice {
  AudioRecorder? _recorder;
  Directory? _recordDirectory;
  DateTime? _recordedAt;
  bool _disposed = false;
  Future<void>? _starting;

  @override
  Future<CapturedEvidence?> photo(
      {required bool camera, required int maxBytes}) async {
    final picked = await ImagePicker().pickImage(
        source: camera ? ImageSource.camera : ImageSource.gallery,
        maxWidth: 4096,
        maxHeight: 4096,
        imageQuality: 90,
        requestFullMetadata: false);
    if (picked == null) return null;
    if (await picked.length() > maxBytes) throw const FormatException('size');
    return CapturedEvidence(await picked.readAsBytes(), picked.name,
        camera ? 'CAMERA' : 'LIBRARY', DateTime.now().toUtc());
  }

  @override
  Future<CapturedEvidence?> file(List<String> mimeTypes, int maxBytes) async {
    const extensions = {
      'application/pdf': 'pdf',
      'image/jpeg': 'jpg',
      'image/png': 'png',
      'audio/wav': 'wav',
      'audio/x-wav': 'wav',
      'audio/mpeg': 'mp3'
    };
    final allowed = mimeTypes
        .map((mime) => extensions[mime])
        .whereType<String>()
        .toSet()
        .toList();
    if (allowed.isEmpty) throw const FormatException('type');
    final result = await FilePicker.pickFiles(
        type: FileType.custom,
        allowedExtensions: allowed,
        allowMultiple: false,
        withData: false);
    if (result == null) return null;
    final picked = result.files.single;
    if (picked.path == null || picked.size > maxBytes) {
      throw const FormatException('size');
    }
    final file = File(picked.path!);
    if (await file.length() > maxBytes) throw const FormatException('size');
    return CapturedEvidence(await file.readAsBytes(), picked.name, 'LIBRARY',
        DateTime.now().toUtc());
  }

  @override
  Future<void> startRecording() {
    if (_disposed) throw StateError('Disposed');
    return _starting = _startRecording();
  }

  Future<void> _startRecording() async {
    _recorder ??= AudioRecorder();
    // Microphone permission is requested only in response to recording.
    if (!await _recorder!.hasPermission()) {
      throw const FileSystemException('microphone denied');
    }
    if (_disposed) throw StateError('Disposed');
    _recordDirectory ??=
        await (await getTemporaryDirectory()).createTemp('nexo-record-');
    if (_disposed) throw StateError('Disposed');
    _recordedAt = DateTime.now().toUtc();
    await _recorder!.start(
        const RecordConfig(
            encoder: AudioEncoder.wav, sampleRate: 16000, numChannels: 1),
        path: '${_recordDirectory!.path}/evidence.wav');
  }

  @override
  Future<CapturedEvidence?> stopRecording(int maxBytes) async {
    final path = await _recorder?.stop();
    if (path == null) return null;
    final file = File(path);
    try {
      if (await file.length() > maxBytes) throw const FormatException('size');
      return CapturedEvidence(
          await file.readAsBytes(), 'recording.wav', 'RECORDER', _recordedAt!);
    } finally {
      if (await file.exists()) await file.delete();
    }
  }

  @override
  Future<void> dispose() async {
    _disposed = true;
    try {
      await _starting;
    } catch (_) {
      // The recording caller handles startup errors; disposal still cleans up.
    }
    await _recorder?.dispose();
    final directory = _recordDirectory;
    if (directory != null && await directory.exists()) {
      await directory.delete(recursive: true);
    }
  }
}
