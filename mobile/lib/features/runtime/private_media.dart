import 'dart:io';
import 'dart:typed_data';

import 'package:flutter/material.dart';
import 'package:just_audio/just_audio.dart';
import 'package:open_filex/open_filex.dart';
import 'package:path_provider/path_provider.dart';

/// Only server-authorized downloads may be passed to [open]. Local selections
/// are previewed as images/audio and are never handed to external applications.
class PrivateMediaFile {
  Directory? _directory;
  bool _disposed = false;
  static const extensions = {
    'application/pdf': 'pdf',
    'image/jpeg': 'jpg',
    'image/png': 'png',
    'audio/wav': 'wav',
    'audio/x-wav': 'wav',
    'audio/mpeg': 'mp3'
  };

  Future<File> write(Uint8List bytes, String mime) async {
    final extension = extensions[mime];
    if (extension == null ||
        bytes.isEmpty ||
        bytes.length > 25 * 1024 * 1024 ||
        _disposed) {
      throw const FormatException('Unsafe media');
    }
    final directory =
        await (await getTemporaryDirectory()).createTemp('nexo-media-');
    if (_disposed) {
      await directory.delete();
      throw StateError('Disposed');
    }
    final previous = _directory;
    _directory = directory;
    if (previous != null && await previous.exists()) {
      await previous.delete(recursive: true);
    }
    return File('${directory.path}/media.$extension')
        .writeAsBytes(bytes, flush: true);
  }

  Future<void> open(Uint8List authorizedBytes, String mime) async {
    final file = await write(authorizedBytes, mime);
    if (_disposed) return;
    final result = await OpenFilex.open(file.path, type: mime);
    if (result.type != ResultType.done) {
      throw const FileSystemException('No safe viewer');
    }
  }

  Future<void> dispose() async {
    _disposed = true;
    final directory = _directory;
    if (directory != null && await directory.exists()) {
      await directory.delete(recursive: true);
    }
  }
}

class PrivateAudioPlayer extends StatefulWidget {
  const PrivateAudioPlayer({required this.load, required this.mime, super.key});
  final Future<Uint8List> Function() load;
  final String mime;
  @override
  State<PrivateAudioPlayer> createState() => _PrivateAudioPlayerState();
}

class _PrivateAudioPlayerState extends State<PrivateAudioPlayer>
    with WidgetsBindingObserver {
  AudioPlayer? _player;
  final _file = PrivateMediaFile();
  bool busy = false;
  bool playing = false;
  String? error;

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addObserver(this);
  }

  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    if (state != AppLifecycleState.resumed) {
      _player?.pause();
      if (mounted) setState(() => playing = false);
    }
  }

  @override
  void dispose() {
    WidgetsBinding.instance.removeObserver(this);
    final player = _player;
    Future<void>(() async {
      await player?.dispose();
      await _file.dispose();
    });
    super.dispose();
  }

  Future<void> toggle() async {
    setState(() {
      busy = true;
      error = null;
    });
    try {
      if (_player == null) {
        final bytes = await widget.load();
        if (!mounted) return;
        final file = await _file.write(bytes, widget.mime);
        if (!mounted) return;
        final player = AudioPlayer();
        _player = player;
        await player.setFilePath(file.path);
        player.playerStateStream.listen((state) {
          if (mounted) {
            setState(() => playing = state.playing &&
                state.processingState != ProcessingState.completed);
          }
        });
      }
      if (!mounted) return;
      if (playing) {
        await _player!.pause();
      } else {
        if (_player!.processingState == ProcessingState.completed) {
          await _player!.seek(Duration.zero);
        }
        // play completes at end of playback; controls remain available meanwhile.
        _player!.play().catchError((Object failure) {
          if (mounted) setState(() => error = 'پخش صدا انجام نشد.');
        });
      }
    } catch (_) {
      final player = _player;
      _player = null;
      await player?.dispose();
      if (mounted) {
        setState(
            () => error = 'پخش صدا انجام نشد. اتصال و فایل را بررسی کنید.');
      }
    } finally {
      if (mounted) setState(() => busy = false);
    }
  }

  @override
  Widget build(BuildContext context) => Column(children: [
        OutlinedButton.icon(
            onPressed: busy ? null : toggle,
            icon: Icon(playing ? Icons.pause : Icons.play_arrow),
            label: Text(busy
                ? 'در حال آماده‌سازی…'
                : playing
                    ? 'توقف پخش'
                    : 'پخش صدا')),
        if (error != null) Text(error!, semanticsLabel: error),
      ]);
}
