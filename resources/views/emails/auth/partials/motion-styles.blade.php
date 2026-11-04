<style>
    @keyframes tan90-shimmer {
        0% { transform: translateX(-150%); }
        100% { transform: translateX(340%); }
    }
    .tan90-shimmer { animation: tan90-shimmer 3.4s ease-in-out infinite; }
    @media (prefers-reduced-motion: reduce) {
        .tan90-shimmer { animation: none !important; }
    }
    @media only screen and (max-width: 600px) {
        .tan90-mail-pad { padding: 28px 22px 16px !important; }
        .tan90-mail-card { border-radius: 12px !important; }
    }
</style>
