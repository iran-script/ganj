import 'package:flutter/cupertino.dart';
import 'package:flutter/material.dart';

abstract final class NexoTheme {
  static const background = Color(0xff0B0920);
  static const surface = Color(0xff171331);
  static const surfaceRaised = Color(0xff211B45);
  static const border = Color(0xff332D59);
  static const primary = Color(0xff8B5CF6);
  static const cyan = Color(0xff22D3EE);
  static const pink = Color(0xffF472B6);
  static const amber = primary;
  static const amberSoft = Color(0xffC4B5FD);
  static const foreground = Color(0xffF8FAFC);
  static const muted = Color(0xffA9A4C4);
  static const success = Color(0xff10B981);
  static const danger = Color(0xffff746d);
  static const information = cyan;
  static const radiusSmall = 12.0;
  static const radius = 16.0;
  static const radiusLarge = 24.0;
  static const cardShadow = <BoxShadow>[
    BoxShadow(color: Color(0x30000000), blurRadius: 20, offset: Offset(0, 8)),
  ];

  static ThemeData get data {
    final scheme = const ColorScheme.dark(
      primary: amber,
      onPrimary: background,
      secondary: cyan,
      onSecondary: background,
      surface: surface,
      onSurface: foreground,
      error: danger,
      onError: background,
      outline: border,
    );
    return ThemeData(
      useMaterial3: true,
      fontFamily: 'Vazirmatn',
      brightness: Brightness.dark,
      colorScheme: scheme,
      scaffoldBackgroundColor: background,
      textTheme: ThemeData.dark()
          .textTheme
          .apply(bodyColor: foreground, displayColor: foreground)
          .copyWith(
            bodySmall: const TextStyle(fontSize: 12, height: 1.55),
            bodyLarge: const TextStyle(fontSize: 16, height: 1.65),
            bodyMedium: const TextStyle(fontSize: 14, height: 1.6),
            titleLarge: const TextStyle(
                fontSize: 20, height: 1.45, fontWeight: FontWeight.w700),
            headlineSmall: const TextStyle(
                fontSize: 26, height: 1.35, fontWeight: FontWeight.w800),
          ),
      appBarTheme: const AppBarTheme(
          backgroundColor: background,
          foregroundColor: foreground,
          surfaceTintColor: Colors.transparent,
          elevation: 0,
          titleTextStyle: TextStyle(fontSize: 20, fontWeight: FontWeight.w700)),
      cardTheme: CardThemeData(
          color: surface,
          elevation: 1,
          shadowColor: const Color(0x40000000),
          surfaceTintColor: Colors.transparent,
          margin: const EdgeInsets.only(bottom: 16),
          shape: RoundedRectangleBorder(
              borderRadius: BorderRadius.circular(radius),
              side: const BorderSide(color: border))),
      filledButtonTheme: FilledButtonThemeData(
          style: FilledButton.styleFrom(
              backgroundColor: amber,
              foregroundColor: background,
              minimumSize: const Size(48, 52),
              padding: const EdgeInsets.symmetric(horizontal: 24, vertical: 16),
              textStyle:
                  const TextStyle(fontSize: 18, fontWeight: FontWeight.w700),
              shape: RoundedRectangleBorder(
                  borderRadius: BorderRadius.circular(radius)))),
      outlinedButtonTheme: OutlinedButtonThemeData(
          style: OutlinedButton.styleFrom(
              foregroundColor: foreground,
              minimumSize: const Size(48, 48),
              side: const BorderSide(color: border),
              shape: RoundedRectangleBorder(
                  borderRadius: BorderRadius.circular(radius)))),
      textButtonTheme: TextButtonThemeData(
          style: TextButton.styleFrom(
              foregroundColor: amberSoft,
              minimumSize: const Size(48, 48),
              textStyle:
                  const TextStyle(fontWeight: FontWeight.w700, fontSize: 15))),
      inputDecorationTheme: InputDecorationTheme(
          filled: true,
          fillColor: background.withValues(alpha: .75),
          contentPadding:
              const EdgeInsets.symmetric(horizontal: 16, vertical: 18),
          border:
              OutlineInputBorder(borderRadius: BorderRadius.circular(radius)),
          enabledBorder: OutlineInputBorder(
              borderRadius: BorderRadius.circular(radius),
              borderSide: const BorderSide(color: border))),
      snackBarTheme: SnackBarThemeData(
          backgroundColor: surfaceRaised,
          behavior: SnackBarBehavior.floating,
          shape: RoundedRectangleBorder(
              borderRadius: BorderRadius.circular(radiusSmall))),
      dialogTheme: DialogThemeData(
          backgroundColor: surface,
          shape: RoundedRectangleBorder(
              borderRadius: BorderRadius.circular(radiusLarge))),
      dividerColor: border,
      progressIndicatorTheme: const ProgressIndicatorThemeData(color: amber),
      navigationBarTheme: NavigationBarThemeData(
        backgroundColor: surface,
        indicatorColor: amber.withValues(alpha: .18),
        elevation: 0,
        labelTextStyle: WidgetStateProperty.resolveWith((states) => TextStyle(
              color: states.contains(WidgetState.selected) ? amberSoft : muted,
              fontSize: 12,
              fontWeight: states.contains(WidgetState.selected)
                  ? FontWeight.w700
                  : FontWeight.w500,
            )),
        iconTheme: WidgetStateProperty.resolveWith((states) => IconThemeData(
              color: states.contains(WidgetState.selected) ? amber : muted,
              size: 23,
            )),
      ),
      bottomSheetTheme: const BottomSheetThemeData(
        backgroundColor: surface,
        modalBackgroundColor: surface,
        showDragHandle: true,
      ),
      pageTransitionsTheme: const PageTransitionsTheme(
        builders: <TargetPlatform, PageTransitionsBuilder>{
          TargetPlatform.android: FadeUpwardsPageTransitionsBuilder(),
          TargetPlatform.iOS: CupertinoPageTransitionsBuilder(),
          TargetPlatform.linux: FadeUpwardsPageTransitionsBuilder(),
          TargetPlatform.macOS: CupertinoPageTransitionsBuilder(),
          TargetPlatform.windows: FadeUpwardsPageTransitionsBuilder(),
        },
      ),
    );
  }
}

class NexoNotice extends StatelessWidget {
  const NexoNotice(this.message,
      {this.icon = Icons.info_outline,
      this.color = NexoTheme.information,
      super.key});
  final String message;
  final IconData icon;
  final Color color;

  @override
  Widget build(BuildContext context) => Semantics(
      liveRegion: true,
      child: Container(
          margin: const EdgeInsets.only(bottom: 16),
          padding: const EdgeInsets.all(16),
          decoration: BoxDecoration(
              color: NexoTheme.surface,
              border: Border.all(color: color),
              borderRadius: BorderRadius.circular(NexoTheme.radius)),
          child: Row(
              textDirection: Directionality.of(context),
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Icon(icon, color: color, size: 24),
                const SizedBox(width: 12),
                Expanded(
                    child: Text(message, style: const TextStyle(height: 1.6))),
              ])));
}
