' Executa scripts\atualizar_atraso.bat sem abrir janela de console (usado pelo Agendador de Tarefas).
Set fso = CreateObject("Scripting.FileSystemObject")
pasta = fso.GetParentFolderName(WScript.ScriptFullName)
CreateObject("WScript.Shell").Run """" & pasta & "\atualizar_atraso.bat""", 0, True
