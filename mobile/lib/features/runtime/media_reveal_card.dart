import 'dart:typed_data';

import 'package:flutter/material.dart';

import '../../core/api/api_client.dart';
import 'private_media.dart';

class MediaRevealCard extends StatefulWidget {
  const MediaRevealCard(
      {required this.node,
      required this.onContinue,
      this.load,
      this.openFile,
      super.key});

  final Map<String, dynamic> node;
  final Future<void> Function() onContinue;
  final Future<Uint8List> Function(String)? load;
  final Future<void> Function(Uint8List, String)? openFile;

  @override
  State<MediaRevealCard> createState() => _MediaRevealCardState();
}

class _MediaRevealCardState extends State<MediaRevealCard> {
  late final Map<String, dynamic> config;
  late final Map<String, dynamic> media;
  Future<Uint8List>? image;
  bool busy = false;
  final file = PrivateMediaFile();
  String? error;

  Future<Uint8List> load() => widget.load != null
      ? widget.load!('/runtime/nodes/${widget.node['id']}/media')
      : ApiClient().downloadMedia('/runtime/nodes/${widget.node['id']}/media',
          expectedMime: media['mime_type'] as String?);

  @override
  void dispose() {
    file.dispose();
    super.dispose();
  }

  Future<void> open() async {
    setState(() {
      busy = true;
      error = null;
    });
    try {
      final mime = media['mime_type'] as String;
      if (!PrivateMediaFile.extensions.containsKey(mime)) {
        throw const FormatException('type');
      }
      final bytes = await load();
      if (!mounted) return;
      await (widget.openFile ?? file.open)(bytes, mime);
    } catch (_) {
      if (mounted) {
        setState(() => error =
            'باز کردن فایل انجام نشد. اتصال و برنامه نمایش فایل را بررسی کنید.');
      }
    } finally {
      if (mounted) setState(() => busy = false);
    }
  }

  @override
  void initState() {
    super.initState();
    config = widget.node['config'] as Map<String, dynamic>? ?? {};
    media = config['media'] as Map<String, dynamic>? ?? {};
    if (media['kind'] == 'IMAGE') {
      image = load();
    }
  }

  @override
  Widget build(BuildContext context) => Card(
      child: Padding(
          padding: const EdgeInsets.all(16),
          child:
              Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
            Text(config['description'] as String? ?? ''),
            const SizedBox(height: 12),
            if (image != null)
              FutureBuilder<Uint8List>(
                  future: image,
                  builder: (context, snapshot) {
                    if (snapshot.hasError) {
                      return const Text(
                          'دریافت تصویر انجام نشد. اتصال اینترنت را بررسی کنید.');
                    }
                    if (!snapshot.hasData) {
                      return const Center(child: CircularProgressIndicator());
                    }
                    return Image.memory(snapshot.data!,
                        semanticLabel:
                            media['name'] as String? ?? 'تصویر مرحله',
                        fit: BoxFit.contain,
                        height: 260,
                        errorBuilder: (_, error, stack) =>
                            const Text('تصویر قابل نمایش نیست.'));
                  })
            else
              Text(
                  '${media['name'] ?? 'رسانه'} • ${media['mime_type'] ?? ''} • ${media['size_bytes'] ?? 0} bytes'),
            const SizedBox(height: 16),
            if (media['kind'] == 'AUDIO')
              PrivateAudioPlayer(
                  load: load, mime: media['mime_type'] as String),
            if (media['kind'] == 'FILE')
              OutlinedButton(
                  onPressed: busy ? null : open,
                  child: const Text('دریافت و باز کردن فایل')),
            if (error != null) Text(error!),
            FilledButton(
                onPressed: busy
                    ? null
                    : () async {
                        setState(() => busy = true);
                        try {
                          await widget.onContinue();
                        } finally {
                          if (mounted) setState(() => busy = false);
                        }
                      },
                child: Text(config['cta_label'] as String? ?? 'ادامه')),
          ])));
}
