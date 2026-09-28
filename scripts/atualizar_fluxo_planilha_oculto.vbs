' Executa scripts\atualizar_fluxo_planilha.bat sem abrir janela de console (usado pelo Agendador de Tarefas).
Set fso = CreateObject("Scripting.FileSystemObject")
pasta = fso.GetParentFolderName(WScript.ScriptFullName)
CreateObject("WScript.Shell").Run """" & pasta & "\atualizar_fluxo_planilha.bat""", 0, True
