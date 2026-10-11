import 'package:flutter/material.dart';

abstract final class NexoTheme {
  static const background = Color(0xff061116);
  static const surface = Color(0xff101f26);
  static const border = Color(0xff40545f);
  static const amber = Color(0xffffb000);
  static const foreground = Color(0xfff2f4f6);
  static const muted = Color(0xffb8c4ce);
  static const success = Color(0xff00cf91);
  static const danger = Color(0xffff746d);
  static const information = Color(0xff80c7ff);
  static const radius = 16.0;

  static ThemeData get data {
    final scheme = const ColorScheme.dark(
      primary: amber,
      onPrimary: background,
      secondary: information,
      onSecondary: background,
      surface: surface,
      onSurface: foreground,
      error: danger,
      onError: background,
      outline: border,
    );
    return ThemeData(
      useMaterial3: true,
      brightness: Brightness.dark,
      colorScheme: scheme,
      scaffoldBackgroundColor: background,
      textTheme: ThemeData.dark()
          .textTheme
          .apply(bodyColor: foreground, displayColor: foreground),
      appBarTheme: const AppBarTheme(
          backgroundColor: background,
          foregroundColor: foreground,
          surfaceTintColor: Colors.transparent,
          elevation: 0,
          titleTextStyle: TextStyle(fontSize: 20, fontWeight: FontWeight.w700)),
      cardTheme: CardThemeData(
          color: surface,
          elevation: 0,
          margin: const EdgeInsets.only(bottom: 16),
          shape: RoundedRectangleBorder(
              borderRadius: BorderRadius.circular(radius),
              side: const BorderSide(color: border))),
      filledButtonTheme: FilledButtonThemeData(
          style: FilledButton.styleFrom(
              backgroundColor: amber,
              foregroundColor: background,
              minimumSize: const Size(48, 56),
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
      inputDecorationTheme: InputDecorationTheme(
          filled: true,
          fillColor: background,
          contentPadding: const EdgeInsets.all(16),
          border:
              OutlineInputBorder(borderRadius: BorderRadius.circular(radius)),
          enabledBorder: OutlineInputBorder(
              borderRadius: BorderRadius.circular(radius),
              borderSide: const BorderSide(color: border))),
      dividerColor: border,
      progressIndicatorTheme: const ProgressIndicatorThemeData(color: amber),
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
          child: Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
            Icon(icon, color: color, size: 24),
            const SizedBox(width: 12),
            Expanded(child: Text(message, style: const TextStyle(height: 1.6))),
          ])));
}
