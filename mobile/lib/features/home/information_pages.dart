import 'package:flutter/material.dart';

import '../../app/nexo_components.dart';
import '../../app/nexo_theme.dart';

class TutorialPage extends StatelessWidget {
  const TutorialPage({super.key});

  @override
  Widget build(BuildContext context) => Scaffold(
        appBar: AppBar(title: const Text('راهنمای بازی')),
        body: SafeArea(
          child: ListView(
            padding: const EdgeInsets.all(NexoSpace.x4),
            children: const [
              NexoCityHeader(
                eyebrow: 'راهنمای بازیکن',
                title: 'از تیم تا خط پایان',
                body: 'برای هر مرحله، وضعیت معتبر از سرور بازی دریافت می‌شود.',
              ),
              _GuideStep(number: '۱', title: 'تیم بسازید یا عضو شوید', body: 'از بخش «تیم من» تیم خود را بسازید یا با کد دعوت به تیم بپیوندید.'),
              _GuideStep(number: '۲', title: 'نشست را انتخاب کنید', body: 'در بخش «بازی‌ها»، نشست دارای ثبت‌نام باز را ببینید. ثبت‌نام را Captain تیم انجام می‌دهد.'),
              _GuideStep(number: '۳', title: 'در لابی آماده شوید', body: 'وضعیت نشست و آمادگی از سرور خوانده می‌شود. شروع رسمی فقط در اختیار GameMaster است.'),
              _GuideStep(number: '۴', title: 'مأموریت‌ها را انجام دهید', body: 'برای GPS یا QR مجوز لازم است. پاسخ نهایی و امتیاز را فقط سرور تأیید می‌کند.'),
              _GuideStep(number: '۵', title: 'نتیجه را بررسی کنید', body: 'پس از پایان نشست، امتیاز و رتبه‌بندی از API دریافت می‌شوند.'),
            ],
          ),
        ),
      );
}

class _GuideStep extends StatelessWidget {
  const _GuideStep({required this.number, required this.title, required this.body});

  final String number;
  final String title;
  final String body;

  @override
  Widget build(BuildContext context) => Card(
        child: ListTile(
          contentPadding: const EdgeInsets.symmetric(horizontal: 16, vertical: 8),
          leading: CircleAvatar(
            backgroundColor: NexoTheme.amber,
            foregroundColor: NexoTheme.background,
            child: Text(number, style: const TextStyle(fontWeight: FontWeight.w800)),
          ),
          title: Text(title, style: const TextStyle(fontWeight: FontWeight.w700)),
          subtitle: Padding(padding: const EdgeInsets.only(top: 6), child: Text(body, style: const TextStyle(height: 1.55))),
        ),
      );
}

class NotificationsPage extends StatelessWidget {
  const NotificationsPage({super.key});

  @override
  Widget build(BuildContext context) => Scaffold(
        appBar: AppBar(title: const Text('اعلان‌ها')),
        body: const SafeArea(
          child: Center(
            child: Padding(
              padding: EdgeInsets.all(NexoSpace.x4),
              child: NexoEmptyView(
                icon: Icons.notifications_none,
                title: 'اعلان سرور در دسترس نیست',
                message: 'در نسخه فعلی Backend، API اعلان‌های بازیکن ارائه نشده است. برای همین این صفحه هیچ اعلان ساختگی نمایش نمی‌دهد.',
              ),
            ),
          ),
        ),
      );
}
