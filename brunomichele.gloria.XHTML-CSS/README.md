L'homework proposto è un tentativo di webgame con 0 linee di javaScript. 
Il tentativo è fallito velocemente  con l'impossibilità di resettare le checkbox che controllano gli stati "gioco" o "menù" tramite il solo HTML/CSS ma ritengo comunque buoni i risultati. 
Non posso fare riferimento ad esercizi in particolare dalle slide come ispirazione ma il processo di creazione è derivato dalle slide stesse: 
pensando ad un modo di utilizzare gli strumenti analizzati in queste è nato il gioco per com'è ora;
con spiegazioni ed esempi basati su strumenti diversi sarebbe nato qualcosa di differente.
A partire dalle pseudo-classi ho cercato di creare una pagina che può essere rappresentata tramite un semplice diagramma a 2 stati,
uno di menù ed uno di gioco, utilizzando la pressione di un bottone per passare allo stato "gioco" ed la  pseudo-classe :hovered per passare a "menu". Presto si è resa necessaria la presenza delle checkbox per tenere traccia dello stato corrente e di conseguenza la :hovered è stato abbandonato per far posto alla piccola parte di javaScript necessaria al funzionamento (CSS non è in grado di modificare lo stato di una checkbox; una volta resa checked non si torna indietro).
Allo stesso modo il resto degli elementi nella pagina sono iniziati come piccoli esperimenti con gli strumenti nelle slide si sono evoluti nella forma attuale (boneBox era nato con tag <table> ma le trasformazioni che subisce per le animazioni mi hanno costretto a cambiarlo).
Tutto questo testo per dire che ho voluto mettere alla prova i limiti di questi linguaggi (anche se non di programmazione) e che per quanto quel che rimane ora forse lo faccia intendere, la maggior parte di questo limit testing è scomparso nelle modifiche fatte nel tempo e nella mia incapacita di ricordarmi di pushare su git prima di cancellare ore di lavoro.

Bruno Michele Gloria