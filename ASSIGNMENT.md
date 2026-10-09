Popis problému:

Chceme mít systém co nejméně vázaný na konkrétní implementace a knihovny třetích stran, proto většinu věcí řešíme přes proxy/adaptér. Teď chceme integrovat Scheduler (plánovač tasků). Vyhlédli jsme dva – Laravel Scheduler (součást frameworku) a alternativní crunz.

Aktuálně použijeme Laravel, ale nechceme se na něj vázat a chceme mít otevřenou cestu k případnému přepnutí na crunz. Součástí je i problém s Laravel Mutexem, jehož implementace je nespolehlivá – chceme proto vlastní mutex.

Cílem je navrhnout interface tříd pro Scheduler a Mutex tak, aby byla implementace Laravel tříd kdykoli vyměnitelná a nevázala systém na Laravel. Návrh prosím doveďte do detailu včetně vstupních parametrů a jejich typů. Aktuální implementace by měla umět plánovat a spouštět crony/joby v daný čas (stačí převzít crontab zápis).

K použití AI:

AI nástroje Vás nijak neomezujeme, bereme je jako přirozenou součást vývoje. Jde nám o něco jiného - výsledkem má být řešení, za kterým si stojíte a které dokážete obhájit i před klientem, ne vygenerovaný a zkopírovaný kód, ale návrh, kterému rozumíte do detailu a který nese Vaše vlastní rozhodnutí. Na případném následném hovoru se totiž budeme bavit nad architekturou, parametry i konkrétními volbami (proč právě takový interface, jaké alternativy se nabízely a jaké to má dopady).

Způsob vypracování necháváme na Vás – ústně, návrh interface, na papír.
