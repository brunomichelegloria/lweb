L'homework proposto è un tentativo di webgame con 0 linee di javaScript. 
Il tentativo è fallito velocemente  con l'impossibilità di resettare le checkbox che controllano gli stati "gioco" o "menù" tramite il solo HTML/CSS ma ritengo comunque buoni i risultati. 
Non posso fare riferimento ad esercizi in particolare dalle slide come ispirazione ma il processo di creazione è derivato dalle slide stesse: 
pensando ad un modo di utilizzare gli strumenti analizzati in queste è nato il gioco per com'è ora;
con spiegazioni ed esempi basati su strumenti diversi sarebbe nato qualcosa di differente.
A partire dalle pseudo-classi ho cercato di creare una pagina che può essere rappresentata tramite un semplice diagramma a 2 stati,
uno di menù ed uno di gioco, utilizzando la pressione di un bottone per passare allo stato "gioco" ed la  pseudo-classe :hovered per passare a "menu". Presto si è resa necessaria la presenza delle checkbox per tenere traccia dello stato corrente e di conseguenza la :hovered è stato abbandonato per far posto alla piccola parte di javaScript necessaria al funzionamento (CSS non è in grado di modificare lo stato di una checkbox; una volta resa checked non si torna indietro).
