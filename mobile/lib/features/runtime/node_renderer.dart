import 'package:flutter/material.dart';

import '../../core/errors/api_error.dart';
import '../location/location_page.dart';
import '../qr/qr_scanner_page.dart';
import 'media_reveal_card.dart';
import 'evidence_card.dart';

class NodeRenderer extends StatelessWidget {
  const NodeRenderer(
      {required this.node,
      required this.sessionId,
      required this.onSubmit,
      this.onEvidenceChanged,
      super.key});

  final Map<String, dynamic> node;
  final String sessionId;
  final Future<void> Function(Map<String, dynamic>) onSubmit;

  final Future<void> Function()? onEvidenceChanged;

  String get type => (node['node_type'] as String? ?? '').toUpperCase();
  Map<String, dynamic> get config =>
      (node['config'] as Map<String, dynamic>?) ?? {};

  Future<void> submitSafely(
      BuildContext context, Map<String, dynamic> value) async {
    try {
      await onSubmit(value);
    } catch (error) {
      if (context.mounted) {
        ScaffoldMessenger.of(context).showSnackBar(SnackBar(
            content: Text(error is ApiError
                ? error.userMessage
                : 'ارسال انجام نشد. دوباره تلاش کنید.')));
      }
    }
  }

  @override
  Widget build(BuildContext context) {
    if (type == 'AR' || type.startsWith('AR ')) {
      return const _UnavailableCard(title: 'قابلیت AR در این نسخه فعال نیست.');
    }
    if (type == 'QR') {
      return Card(
          child: ListTile(
              leading: const Icon(Icons.qr_code_scanner),
              title: const Text('اسکن کد QR'),
              onTap: () => Navigator.push(
                  context,
                  MaterialPageRoute(
                      builder: (_) => QrScannerPage(
                          sessionId: sessionId,
                          requireFreshLocation: config['requires_gps'] == true,
                          onValue: (value) =>
                              onSubmit({'qr_token': value}))))));
    }
    if (type == 'MEDIAREVEAL') {
      return MediaRevealCard(
          key: ValueKey(node['id']),
          node: node,
          onContinue: () => submitSafely(context, {'acknowledged': true}));
    }
    if (['PHOTOSUBMISSION', 'AUDIOSUBMISSION', 'FILESUBMISSION']
        .contains(type)) {
      return EvidenceCard(
          key: ValueKey(node['id']),
          node: node,
          sessionId: sessionId,
          onChanged: onEvidenceChanged);
    }
    if (type.contains('GPS') || type == 'CHECKPOINT') {
      return Card(
          child: ListTile(
              leading: const Icon(Icons.location_on),
              title: const Text('بررسی موقعیت'),
              onTap: () => Navigator.push(
                  context,
                  MaterialPageRoute(
                      builder: (_) => LocationPage(
                          sessionId: sessionId, onLocation: onSubmit)))));
    }
    if (type.contains('PUZZLE') ||
        type.contains('TEXT ANSWER') ||
        type == 'PASSWORD') {
      return _AnswerCard(
          onSubmit: (value) => submitSafely(context, value),
          prompt: config['prompt'] as String? ?? 'پاسخ را وارد کنید.');
    }
    if (['TEAM TOGETHER', 'TEAMTOGETHER', 'BARRIER', 'RELAY'].contains(type)) {
      return _ProgressCard(
          node: node, onSubmit: (value) => submitSafely(context, value));
    }
    if (type == 'MESSAGE' ||
        type == 'SHOW MESSAGE' ||
        type == 'SPLITINFORMATION') {
      return _AcknowledgeCard(
          text: config['text'] as String? ?? config['prompt'] as String? ?? '',
          onSubmit: (value) => submitSafely(context, value));
    }
    if (type == 'FINISH') {
      return _AcknowledgeCard(
          text: 'به مرحله پایانی رسیدید. پایان بازی را تأیید کنید.',
          onSubmit: (value) => submitSafely(context, value));
    }
    if (type == 'WAIT') {
      return const _UnavailableCard(
          title: 'این مرحله تا زمان مجاز منتظر می‌ماند.');
    }
    if (type == 'TIME') {
      return config['mode'] == 'DELAY'
          ? _UnavailableCard(
              title:
                  'در انتظار زمان سرور • ${config['duration_seconds']} ثانیه')
          : _AcknowledgeCard(
              text: config['mode'] == 'TIME_WINDOW'
                  ? 'این مرحله فقط در بازه تعیین‌شده توسط سرور پذیرفته می‌شود.'
                  : 'پیش از پایان مهلت سرور، ادامه را انتخاب کنید.',
              onSubmit: (value) => submitSafely(context, value));
    }
    if (type == 'LIVECHALLENGE') {
      return _AcknowledgeCard(
          text: config['instruction'] as String? ??
              'منتظر چالش GameMaster بمانید؛ پس از تکمیل چالش، ادامه را انتخاب کنید.',
          onSubmit: (value) => submitSafely(context, value));
    }
    if ([
      'CONDITION',
      'SETVARIABLE',
      'CHECKVARIABLE',
      'SCORE',
      'ROLECHECK',
      'ASSIGNROLE',
      'RANDOMPLAYER'
    ].contains(type)) {
      return const _UnavailableCard(title: 'در انتظار مرحله بعد از سرور');
    }
    return const _UnavailableCard(
        title: 'محتوای این مرحله در نسخه فعلی پشتیبانی نمی‌شود.');
  }
}

class _AnswerCard extends StatefulWidget {
  const _AnswerCard({required this.onSubmit, required this.prompt});

  final Future<void> Function(Map<String, dynamic>) onSubmit;
  final String prompt;

  @override
  State<_AnswerCard> createState() => _AnswerCardState();
}

class _AnswerCardState extends State<_AnswerCard> {
  final controller = TextEditingController();
  bool sending = false;

  @override
  void dispose() {
    controller.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) => Card(
      child: Padding(
          padding: const EdgeInsets.all(16),
          child:
              Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
            Text(widget.prompt),
            const SizedBox(height: 12),
            TextField(
                controller: controller,
                decoration: const InputDecoration(
                    border: OutlineInputBorder(), labelText: 'پاسخ')),
            const SizedBox(height: 12),
            FilledButton(
                onPressed: sending
                    ? null
                    : () async {
                        setState(() => sending = true);
                        await widget.onSubmit({'answer': controller.text});
                        if (mounted) setState(() => sending = false);
                      },
                child: const Text('ارسال'))
          ])));
}

class _AcknowledgeCard extends StatelessWidget {
  const _AcknowledgeCard({required this.text, required this.onSubmit});

  final String text;
  final Future<void> Function(Map<String, dynamic>) onSubmit;

  @override
  Widget build(BuildContext context) => Card(
      child: Padding(
          padding: const EdgeInsets.all(16),
          child:
              Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
            Text(text),
            const SizedBox(height: 12),
            FilledButton(
                onPressed: () => onSubmit({'acknowledged': true}),
                child: const Text('ادامه'))
          ])));
}

class _ProgressCard extends StatelessWidget {
  const _ProgressCard({required this.node, required this.onSubmit});

  final Map<String, dynamic> node;
  final Future<void> Function(Map<String, dynamic>) onSubmit;

  @override
  Widget build(BuildContext context) => Card(
      child: Padding(
          padding: const EdgeInsets.all(16),
          child:
              Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
            const Text('پیشرفت همکاری تیم'),
            const SizedBox(height: 8),
            Text('پیشرفت: ${node['progress'] ?? 0}'),
            const SizedBox(height: 12),
            FilledButton(
                onPressed: () => onSubmit({'acknowledged': true}),
                child: const Text('ثبت مشارکت'))
          ])));
}

class _UnavailableCard extends StatelessWidget {
  const _UnavailableCard({required this.title});

  final String title;

  @override
  Widget build(BuildContext context) => Card(
      child: Padding(padding: const EdgeInsets.all(16), child: Text(title)));
}
